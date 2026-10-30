<?php

declare(strict_types=1);

use App\Domain\Collections\Enums\ReminderKind;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Bank\BankDetails;
use App\Domain\Guard\BankChanges\BankChangeService;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Receiving\ReceivingDetailsService;
use App\Domain\Guard\Trusted\TrustedConfirmations;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Notifications\Routing\NotificationRouter;
use App\Domain\Notifications\Routing\ReceivablesNotifications;
use App\Domain\Payments\Enums\PayableReason;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Payments\Recording\PaymentRecorder;
use App\Domain\Receivables\Models\Invoice;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\MemberService;
use Carbon\CarbonImmutable;
use Tests\Feature\Payflow\Support\Guard;
use Tests\Feature\Payflow\Support\Payflow;
use Tests\Feature\Payflow\Support\RevokedKeyGateway;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    $this->workspace = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $this->members = [];

    foreach ([
        ['anna', WorkspaceRole::Owner, 'en'], ['oleg', WorkspaceRole::Owner, 'ru'], ['mark', WorkspaceRole::Finance, 'en'], ['nina', WorkspaceRole::Finance, 'ru'],
        ['dmitry', WorkspaceRole::Approver, 'en'], ['ivan', WorkspaceRole::Approver, 'ru'], ['olga', WorkspaceRole::Admin, 'ru'], ['pavel', WorkspaceRole::Member, 'ru'],
    ] as $index => [$name, $role, $locale]) {
        $user = Guard::member($this->workspace, $role);
        $user->forceFill(['locale' => $locale, 'name' => ucfirst($name)])->save();
        Guard::telegram($user, $this->workspace, 710000000 + $index);
        $this->members[$name] = $user->refresh();
    }

    $this->vendor = Guard::vendor($this->workspace, $this->members['mark'], ['name' => 'Copy <Lab> & Co', 'legal_name' => 'Copy Lab LLC', 'country' => 'LT', 'default_currency' => 'EUR', 'email' => 'billing@copylab.example']);
    Guard::verifiedAccount($this->vendor, $this->members['mark'], $this->members['dmitry'], Guard::iban('Copy Lab LLC'));
    $this->vendor->refresh();
});

function captureNotifications(Closure $action): array
{
    OutboxMessage::query()->delete();
    $action();

    return OutboxMessage::query()->whereIn('topic', ['telegram.notify', 'email.send'])->orderBy('id')->get()->map(fn (OutboxMessage $message): array => [
        'topic' => $message->topic,
        'locale' => $message->payload['locale'] ?? null,
        'text' => $message->topic === 'telegram.notify' ? (string) $message->payload['text_html'] : (string) ($message->payload['subject'] ?? '')."\n".(string) ($message->payload['text'] ?? ''),
        'payload' => $message->payload,
    ])->all();
}

function assertRenderedCleanly(array $messages, string $label): void
{
    expect($messages)->not->toBeEmpty("{$label}: nothing was sent");

    foreach ($messages as $message) {
        $text = $message['text'];

        expect($text)->not->toMatch('/(?<![\w:\/]):[a-z][a-z_]+\b/', "{$label}: unreplaced placeholder in {$text}")
            ->and($text)->not->toMatch('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', "{$label}: emoji in {$text}")
            ->and($text)->not->toContain('payflow.telegram.');

        if ($message['topic'] === 'telegram.notify') {
            expect($text)->not->toContain('<Lab>');
            expect(preg_replace('/<\/?(b|i|a|code)(\s[^>]*)?>/', '', $text))->not->toMatch('/<[a-z\/]/i', "{$label}: unsupported tag in {$text}")
                ->and(count($message['payload']['buttons']))->toBeLessThanOrEqual(10);

            foreach ($message['payload']['buttons'] as $button) {
                expect(isset($button['url']) || isset($button['callback']))->toBeTrue();
            }
        }

        if ($message['locale'] === 'ru') {
            expect($text)->toMatch('/\p{Cyrillic}/u', "{$label}: Russian recipient got {$text}");
        }

        if ($message['locale'] === 'en') {
            expect(preg_replace('/\p{Cyrillic}+/u', '', $text) === $text)->toBeTrue("{$label}: English recipient got {$text}");
        }
    }
}

it('renders every Guard notification in the recipient locale without placeholders, emoji or raw user markup', function (): void {
    $notifications = app(GuardNotifications::class);
    $ws = $this->workspace;
    $m = $this->members;
    $runAs = fn (Closure $callback) => app(CurrentWorkspace::class)->runAs($ws, $callback);

    $payout = Guard::payout($ws, $m['mark'], $this->vendor, 290000);
    $change = $runAs(fn () => app(BankChangeService::class)->request(
        $this->vendor, $m['mark'], BankDetails::fromInput(Guard::iban('PF Payments UAB', 'LT583250067741200519')), BankChangeSource::VendorEmail,
        'billing@copy1ab.example', 'Urgent, do not call. New number +370 600 41 977',
    ));
    $approval = new PayoutApproval;
    $approval->forceFill(['decision' => 'approved', 'comment' => 'Looks <fine>', 'user_id' => $m['dmitry']->id]);
    $approval->setRelation('user', $m['dmitry']);
    $version = $runAs(fn (): ReceivingDetailsVersion => app(ReceivingDetailsService::class)->request($ws, $m['anna'], 'IBAN LV80BANK0000435195001'));
    $contact = TrustedContact::query()->create([
        'workspace_id' => $ws->id, 'name' => 'Lena', 'email' => 'lena@accounting.example', 'locale' => 'ru',
        'status' => TrustedContactStatus::Active, 'verified_at' => now(), 'active_from' => now(), 'created_by' => $m['anna']->id,
    ]);
    $confirmation = $runAs(fn (): TrustedConfirmation => app(TrustedConfirmations::class)->create($contact, TrustedSubjectType::PayoutRequest, $payout->id, ['number' => $payout->number], $m['anna']));

    $cases = [
        'bank_change_requested' => fn () => $notifications->bankChangeRequested($change->forceFill(['risk_band' => 'medium'])),
        'bank_change_high_risk' => fn () => $notifications->bankChangeRequested($change->forceFill(['risk_band' => 'high'])),
        'payout_pending_approval' => fn () => $notifications->payoutPendingApproval($payout),
        'payout_stale_approval' => fn () => $notifications->payoutStale($payout, 30),
        'payout_decided' => fn () => $notifications->payoutDecided($payout, $approval),
        'payout_released' => fn () => $notifications->payoutReleased($payout->forceFill(['paid_reference' => 'TRF <1>']), $m['anna']),
        'guard_settings_changed' => fn () => $notifications->guardSettingsChanged($ws->id, $m['anna'], 'thresholds', true, null),
        'guard_role_granted' => fn () => $notifications->guardRoleGranted($ws, $m['anna'], $m['pavel'], WorkspaceRole::Approver, now()->addHours(72), null),
        'guard_role_revoked' => fn () => $notifications->guardRoleRevoked($ws, $m['oleg'], $m['ivan'], WorkspaceRole::Approver, null),
        'receiving_details_change_requested' => fn () => $notifications->receivingDetailsChangeRequested($version, '5001'),
        'receiving_details_changed' => fn () => $notifications->receivingDetailsChanged($version, null),
        'trusted_contact_changed' => fn () => $notifications->trustedContactChanged($contact, $m['anna']),
        'insufficient_approvers' => fn () => $notifications->insufficientApprovers($payout, 2, 1),
        'second_factor_missing' => fn () => $notifications->secondFactorMissing($ws->id, $m['ivan']),
        'trusted_confirmation_expired' => fn () => $notifications->trustedConfirmationExpired($confirmation),
        'bank_change_verified' => fn () => $notifications->bankChangeDecided($change, $m['dmitry'], true),
        'bank_change_rejected' => fn () => $notifications->bankChangeDecided($change, $m['dmitry'], false),
    ];

    foreach ($cases as $label => $case) {
        $messages = $runAs(fn (): array => captureNotifications($case));
        assertRenderedCleanly($messages, $label);
    }

    $pending = $runAs(fn (): array => captureNotifications(fn () => $notifications->payoutPendingApproval($payout)));
    $ruApprover = collect($pending)->firstWhere('payload.user_id', $m['ivan']->id);

    expect($ruApprover['text'])->toContain('<b>Выплата ждёт вашего согласования</b>')
        ->and($ruApprover['text'])->toContain('Оценка риска — подсказка, а не гарантия.')
        ->and($ruApprover['text'])->toContain('Copy &lt;Lab&gt; &amp; Co')
        ->and(collect($pending)->pluck('payload.user_id')->filter()->sort()->values()->all())
        ->toBe(collect([$m['anna']->id, $m['oleg']->id, $m['dmitry']->id, $m['ivan']->id, $m['olga']->id])->sort()->values()->all());

    $decided = $runAs(fn (): array => captureNotifications(fn () => $notifications->payoutDecided($payout->forceFill(['required_approvals' => 2, 'approvals_count' => 0]), $approval)));
    $requester = collect($decided)->firstWhere('payload.user_id', $m['mark']->id);

    expect($requester['text'])->toContain('2 more approvals needed.');

    $m['mark']->forceFill(['locale' => 'ru'])->save();
    $decidedRu = $runAs(fn (): array => captureNotifications(fn () => $notifications->payoutDecided($payout->forceFill(['required_approvals' => 2, 'approvals_count' => 1]), $approval)));

    expect(collect($decidedRu)->firstWhere('payload.user_id', $m['mark']->id)['text'])->toContain('Нужно ещё 1 согласование.');
});

it('renders the receivables notifications for their default recipients in both locales', function (): void {
    $notifications = app(ReceivablesNotifications::class);
    $ws = $this->workspace;
    $m = $this->members;
    $client = Payflow::client($ws, ['name' => 'Helios <Media>', 'owner_user_id' => $m['pavel']->id, 'email' => 'ap@helios.example', 'phone' => '+371 2000 0000']);
    $invoice = Payflow::invoice($ws, $m['anna'], $client, '2026-09-01', '2026-09-30');
    $runAs = fn (Closure $callback) => app(CurrentWorkspace::class)->runAs($ws, $callback);
    $payment = $runAs(fn () => app(PaymentRecorder::class)->recordManual($invoice, $m['nina'], 40000, '2026-10-21', PaymentMethod::BankTransfer, 'TRF-1', null, false, false));
    $reminder = new Reminder;
    $reminder->forceFill([
        'workspace_id' => $ws->id, 'invoice_id' => $invoice->id, 'client_id' => $client->id, 'kind' => ReminderKind::Manual, 'tone' => Tone::Firm,
        'status' => ReminderStatus::Failed, 'locale' => 'en', 'scheduled_for' => now(), 'recipient_email' => 'ap@helios.example', 'error' => '550 <mailbox> unavailable',
    ])->save();

    $cases = [
        'payment_received' => fn () => $notifications->paymentReceived($invoice->refresh(), $payment),
        'reminder_failed' => fn () => $notifications->reminderFailed($reminder),
        'reminder_escalation' => fn () => $notifications->reminderEscalation($invoice, $reminder, '2026-10-22'),
        'collections_escalation' => fn () => $notifications->collectionsEscalation($invoice, '2026-10-22'),
        'reminders_opted_out' => fn () => $notifications->remindersOptedOut($client),
        'client_reported_paid' => fn () => $notifications->clientReportedPaid($invoice),
        'payment_provider_error' => fn () => $notifications->paymentProviderError($ws->id, 1, 'stripe', 'provider_unavailable'),
        'payment_account_restricted' => fn () => $notifications->paymentAccountRestricted($ws->id, 1, 'stripe', 'forbidden'),
    ];

    foreach ($cases as $label => $case) {
        $messages = $runAs(fn (): array => captureNotifications($case));
        assertRenderedCleanly($messages, $label);
    }

    $received = $runAs(fn (): array => captureNotifications(fn () => $notifications->paymentReceived($invoice->refresh(), $payment)));
    $restricted = $runAs(fn (): array => captureNotifications(fn () => $notifications->paymentAccountRestricted($ws->id, 1, 'stripe', 'forbidden')));

    expect(collect($received)->pluck('payload.user_id')->filter()->sort()->values()->all())
        ->toBe(collect([$m['anna']->id, $m['oleg']->id, $m['mark']->id, $m['nina']->id, $m['pavel']->id])->sort()->values()->all())
        ->and(collect($restricted)->where('topic', 'telegram.notify')->pluck('payload.user_id')->sort()->values()->all())
        ->toBe(collect([$m['anna']->id, $m['oleg']->id, $m['olga']->id])->sort()->values()->all())
        ->and(collect($restricted)->where('topic', 'email.send')->count())->toBe(3)
        ->and(collect($restricted)->firstWhere('payload.user_id', $m['oleg']->id)['text'])->toContain('у ключа нет нужного разрешения');
});

it('applies the default notification preferences of every role exactly as specified', function (): void {
    $expected = [
        'owner' => ['payment_received', 'collections_escalation', 'reminders_opted_out', 'bank_change_requested', 'bank_change_high_risk', 'payout_pending_approval', 'payout_decided', 'payout_released', 'guard_settings_changed', 'guard_role_granted', 'receiving_details_change', 'payment_account_issue', 'daily_digest'],
        'admin' => ['bank_change_requested', 'bank_change_high_risk', 'payout_pending_approval', 'payout_decided', 'guard_settings_changed', 'guard_role_granted', 'receiving_details_change', 'payment_account_issue'],
        'finance' => ['payment_received', 'reminder_failed', 'collections_escalation', 'reminders_opted_out', 'client_reported_paid', 'bank_change_high_risk', 'payout_decided', 'payout_released', 'guard_role_granted', 'receiving_details_change', 'daily_digest'],
        'approver' => ['bank_change_requested', 'bank_change_high_risk', 'payout_pending_approval', 'guard_settings_changed', 'guard_role_granted', 'receiving_details_change', 'daily_digest'],
        'member' => ['payment_received', 'reminder_failed', 'collections_escalation', 'reminders_opted_out', 'client_reported_paid', 'daily_digest'],
        'external_approver' => ['bank_change_requested', 'bank_change_high_risk', 'payout_pending_approval', 'guard_role_granted'],
    ];
    $forced = [
        'owner' => ['bank_change_high_risk', 'guard_settings_changed', 'guard_role_granted', 'receiving_details_change', 'payment_account_issue'],
        'admin' => ['guard_role_granted'], 'finance' => ['guard_role_granted'], 'approver' => ['guard_role_granted'], 'member' => [], 'external_approver' => ['guard_role_granted'],
    ];

    foreach (WorkspaceRole::cases() as $role) {
        $events = NotificationRouter::defaultEvents($role);
        sort($events);
        $want = $expected[$role->value];
        sort($want);
        $wantForced = $forced[$role->value];
        $actualForced = NotificationRouter::forcedEvents($role);
        sort($wantForced);
        sort($actualForced);

        expect($events)->toBe($want, $role->value)->and($actualForced)->toBe($wantForced, $role->value);
    }
});

it('pauses card payments when the provider rejects the key and resumes when it is replaced', function (): void {
    $client = Payflow::client($this->workspace, ['email' => 'ap@client.example']);
    $invoice = Payflow::invoice($this->workspace, $this->members['anna'], $client, '2026-10-01', '2026-10-30');
    $other = Payflow::invoice($this->workspace, $this->members['anna'], $client, '2026-10-02', '2026-10-31');
    $account = Payflow::fakeAccount($this->workspace);
    $link = function (Invoice $target, int $amount, string $id) use ($account): InvoicePaymentLink {
        $model = new InvoicePaymentLink;
        $model->forceFill([
            'workspace_id' => $target->workspace_id, 'invoice_id' => $target->id, 'payment_account_id' => $account->id, 'driver' => 'fake', 'kind' => 'reusable',
            'provider_id' => $id, 'url' => 'https://pay.example/'.$id, 'amount' => $amount, 'currency' => $target->currency, 'status' => 'pending',
            'reference' => 'invoice:'.$target->id.':1', 'active' => true,
        ])->save();

        return $model;
    };
    $link($invoice, 1, 'plink_stale');
    $existing = $link($other, $other->balanceDue(), 'plink_other');
    config(['billing.payment_gateways.fake' => RevokedKeyGateway::class]);
    RevokedKeyGateway::$status = 403;
    $service = app(PaymentLinkService::class);

    $messages = app(CurrentWorkspace::class)->runAs($this->workspace, fn (): array => captureNotifications(fn () => $service->ensureActive($invoice)));
    $alert = GuardAlert::query()->where('dedupe_key', 'par:'.$account->id)->sole();
    $emails = collect($messages)->where('topic', 'email.send');

    expect($alert->type->value)->toBe('payment_account_restricted')
        ->and($alert->severity->value)->toBe('critical')
        ->and($existing->refresh()->active)->toBeFalse()
        ->and($existing->deactivation_reason?->value)->toBe('account_restricted')
        ->and(app(CurrentWorkspace::class)->runAs($this->workspace, fn () => $service->payableReason($invoice)))->toBe(PayableReason::AccountRestricted)
        ->and(AuditLog::query()->where('action', 'payflow.payment_account.restricted')->count())->toBe(1)
        ->and($emails->pluck('payload.reference.type')->unique()->all())->toBe(['guard_alert'])
        ->and($emails->pluck('payload.reference.id')->unique()->all())->toBe([$alert->id])
        ->and($emails->pluck('payload.to')->sort()->values()->all())->toBe(collect([$this->members['anna'], $this->members['oleg'], $this->members['olga']])->pluck('email')->sort()->values()->all());

    foreach ($emails as $email) {
        expect(Payflow::emailPayloadErrors($email['payload']))->toBeNull();
    }

    $again = app(CurrentWorkspace::class)->runAs($this->workspace, fn (): array => captureNotifications(fn () => $service->ensureActive($invoice)));

    expect($again)->toBe([]);

    RevokedKeyGateway::$status = 0;
    GuardAlert::query()->whereKey($alert->id)->update(['status' => 'resolved']);
    $timeout = app(CurrentWorkspace::class)->runAs($this->workspace, fn (): array => captureNotifications(fn () => $service->ensureActive($invoice->refresh())));

    expect($timeout)->toBe([])
        ->and(GuardAlert::query()->where('workspace_id', $this->workspace->id)->whereIn('type', ['payment_provider_error', 'payment_account_restricted'])->where('status', '!=', 'resolved')->exists())->toBeFalse();

    $mismatch = app(CurrentWorkspace::class)->runAs($this->workspace, fn (): array => captureNotifications(fn () => app(PaymentRecorder::class)->recordProvider(
        $other->refresh(), $existing->refresh(), 'pi_mismatch', 1000, 'RUB', CarbonImmutable::parse('2026-10-22T09:00:00Z'),
    )));
    $providerAlert = GuardAlert::query()->where('dedupe_key', 'ppe:'.$account->id)->sole();

    expect($providerAlert->data['error'] ?? null)->toBe('currency_mismatch')
        ->and($providerAlert->data['number'] ?? null)->toBe($other->label())
        ->and(collect($mismatch)->where('topic', 'telegram.notify')->pluck('text')->first())->toContain('a payment arrived in a different currency than the invoice')
        ->and(collect($mismatch)->where('topic', 'email.send')->pluck('payload.reference.type')->unique()->all())->toBe(['guard_alert'])
        ->and($other->refresh()->balanceDue())->toBe($other->total_minor);
});

it('tells owners when a member loses a privileged role and invalidates their open approvals', function (): void {
    $payout = Guard::payout($this->workspace, $this->members['mark'], $this->vendor, 1250000);
    $this->actingAs($this->members['dmitry'])->withSession(Guard::stepUp())->post(route('payouts.approve', $payout))->assertRedirect();
    OutboxMessage::query()->delete();

    app(CurrentWorkspace::class)->runAs($this->workspace, fn () => app(MemberService::class)->changeRole($this->workspace, $this->members['anna'], $this->members['dmitry'], WorkspaceRole::Member));

    $notified = OutboxMessage::query()->where('topic', 'telegram.notify')->where('payload->reference->type', 'guard_role_revoked')->pluck('payload')->all();

    expect(collect($notified)->pluck('user_id')->all())->toBe([$this->members['oleg']->id])
        ->and($notified[0]['text_html'])->toContain('Права на согласование платежей отозваны')
        ->and(GuardAlert::query()->where('type', 'guard_role_revoked')->exists())->toBeTrue()
        ->and(PayoutApproval::query()->where('payout_request_id', $payout->id)->whereNotNull('invalidated_at')->count())->toBe(1)
        ->and($payout->refresh()->approvals_count)->toBe(0)
        ->and(User::query()->find($this->members['dmitry']->id)?->roleIn($this->workspace))->toBe(WorkspaceRole::Member);
});
