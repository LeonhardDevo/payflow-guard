<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Routing;

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Eligibility\EligibleMember;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\NotificationRenderer;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class GuardNotifications
{
    public function __construct(
        private NotificationRouter $router,
        private GuardEligibility $eligibility,
    ) {}

    public function bankChangeRequested(VendorBankAccount $account, ?GuardAlert $alert = null): int
    {
        $vendor = $account->vendor;
        $requester = $account->requester;
        $verifiers = $this->eligibleIds($account->workspace_id, 'bank_changes.verify', $requester->id);
        $audience = array_values(array_unique([...$verifiers, ...$this->ownerIds($account->workspace_id)]));
        $high = $account->risk_band === RiskBand::High;
        $render = function () use ($account, $vendor, $requester, $high): array {
            $lines = [
                NotificationRenderer::line('bank_change_requested.vendor', ['vendor' => $vendor->name]),
                NotificationRenderer::line('bank_change_requested.account', ['country' => $account->bank_country, 'last4' => $account->last4, 'currency' => $account->currency]),
                NotificationRenderer::line('bank_change_requested.risk', [
                    'band' => (string) __('payflow.risk_bands.'.$account->risk_band->value),
                    'score' => $account->risk_score,
                    'signals' => NotificationRenderer::signals($account->risk_signals),
                ]),
                NotificationRenderer::line('bank_change_requested.requested_by', [
                    'user' => UserRef::name($requester),
                    'source' => (string) __('payflow.sources.'.$account->source->value),
                ]),
                NotificationRenderer::line('bank_change_requested.callback'),
            ];

            if ($high) {
                $lines[] = NotificationRenderer::line('bank_change_high_risk.on_hold', ['vendor' => $vendor->name]);
            }

            return NotificationRenderer::render($high ? 'bank_change_high_risk' : 'bank_change_requested', [], $lines);
        };
        $buttons = fn (): array => self::withAcknowledge([['text' => (string) __('payflow.telegram.buttons.review_in_app'), 'url' => route('guard.bank-changes.show', $account->id)]], $alert);

        return $this->router->send(
            $account->workspace_id,
            $high ? NotificationEvent::BankChangeHighRisk : NotificationEvent::BankChangeRequested,
            $render,
            ['type' => 'vendor_bank_account', 'id' => $account->id],
            forced: $high,
            buttons: $buttons,
            audience: $high ? null : $audience,
            exclude: [$requester->id],
            critical: $high,
            alertId: $alert?->id,
        );
    }

    public function payoutPendingApproval(PayoutRequest $payout): int
    {
        $payout->loadMissing(['vendor', 'requester', 'bankAccount']);
        $targets = array_values(array_diff($this->eligibleIds($payout->workspace_id, 'payouts.approve', $payout->requested_by), $this->decidedIds($payout)));

        if ($targets === []) {
            return 0;
        }

        $webOnly = ! PayoutService::telegramApprovalAllowed($payout);

        return $this->router->send(
            $payout->workspace_id,
            NotificationEvent::PayoutPendingApproval,
            function (string $locale) use ($payout, $webOnly): array {
                $amount = MoneyFormatter::format($payout->amount_minor, $payout->currency, $locale);
                $signals = NotificationRenderer::signals($payout->risk_signals);
                $lines = [
                    NotificationRenderer::line('payout_pending_approval.line', [
                        'number' => $payout->number,
                        'html_amount' => '<b>'.e($amount).'</b>',
                        'vendor' => $payout->vendor->name,
                        'last4' => $payout->bankAccount !== null ? $payout->bankAccount->last4 : '----',
                    ]),
                    e($payout->description),
                    NotificationRenderer::line('payout_pending_approval.risk', [
                        'band' => (string) __('payflow.risk_bands.'.($payout->risk_band ?? RiskBand::Low)->value),
                        'score' => (int) $payout->risk_score,
                        'html_signals' => $signals !== '' ? ' — '.e($signals) : '',
                    ]),
                    NotificationRenderer::line('payout_pending_approval.approvals', [
                        'count' => $payout->approvals_count,
                        'required' => $payout->required_approvals,
                        'user' => UserRef::name($payout->requester),
                    ]),
                ];

                if ($webOnly) {
                    $lines[] = NotificationRenderer::line('payout_pending_approval.web_only');
                }

                return NotificationRenderer::render('payout_pending_approval', [], $lines);
            },
            ['type' => 'payout', 'id' => $payout->id],
            buttons: fn (): array => $this->decisionButtons($payout),
            audience: $targets,
        );
    }

    public function payoutStale(PayoutRequest $payout, int $hours): int
    {
        $payout->loadMissing('vendor');
        $undecided = array_values(array_diff($this->eligibleIds($payout->workspace_id, 'payouts.approve', $payout->requested_by), $this->decidedIds($payout)));
        $targets = array_values(array_unique([...$undecided, ...$this->ownerIds($payout->workspace_id)]));

        return $this->router->send(
            $payout->workspace_id,
            NotificationEvent::PayoutPendingApproval,
            fn (string $locale): array => NotificationRenderer::render('payout_stale_approval', [
                'number' => $payout->number,
                'amount' => MoneyFormatter::format($payout->amount_minor, $payout->currency, $locale),
                'vendor' => $payout->vendor->name,
                'hours' => $hours,
            ]),
            ['type' => 'payout_stale', 'id' => $payout->id],
            buttons: fn (): array => $this->decisionButtons($payout),
            onlyUserIds: array_values(array_diff($targets, [$payout->requested_by])),
        );
    }

    /** @return list<array{text: string, url?: string, callback?: string}> */
    private function decisionButtons(PayoutRequest $payout): array
    {
        $open = ['text' => (string) __('payflow.telegram.buttons.open_in_app'), 'url' => route('payouts.show', $payout->id)];
        $fp8 = substr((string) $payout->bank_fingerprint, 0, 8);

        if (! PayoutService::telegramApprovalAllowed($payout) || $fp8 === '') {
            return [$open];
        }

        return [
            ['text' => (string) __('payflow.telegram.buttons.approve'), 'callback' => 'pf.pa:'.$payout->id.':'.$fp8],
            ['text' => (string) __('payflow.telegram.buttons.reject'), 'callback' => 'pf.pr:'.$payout->id.':'.$fp8],
            $open,
        ];
    }

    public function payoutDecided(PayoutRequest $payout, PayoutApproval $approval, ?string $actorName = null): int
    {
        $payout->loadMissing('vendor');
        $approved = $approval->decision->value === 'approved';
        $releasers = $approved && $payout->status->value === 'approved' ? $this->eligibleIds($payout->workspace_id, 'payouts.release', null) : [];
        $actor = $actorName ?? ($approval->user !== null ? UserRef::name($approval->user) : '');
        $remaining = max(0, $payout->required_approvals - $payout->approvals_count);

        return $this->router->send(
            $payout->workspace_id,
            NotificationEvent::PayoutDecided,
            fn (): array => [
                'title' => NotificationRenderer::line('payout_decided.'.($approved ? 'approved' : 'rejected'), ['number' => $payout->number]),
                'body' => trim(NotificationRenderer::line('payout_decided.by', [
                    'user' => $actor,
                    'comment' => $approval->comment !== null && $approval->comment !== '' ? ': '.$approval->comment : '',
                ]).' '.($approved ? ($remaining > 0
                    ? (string) trans_choice('payflow.telegram.payout_decided.more', $remaining, ['count' => $remaining])
                    : NotificationRenderer::line('payout_decided.ready')) : '')),
            ],
            ['type' => 'payout_decision', 'id' => $approval->id],
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.buttons.open_payout'), 'url' => route('payouts.show', $payout->id)]],
            onlyUserIds: array_values(array_unique([$payout->requested_by, ...$releasers])),
        );
    }

    public function payoutReleased(PayoutRequest $payout, User $actor): int
    {
        $payout->loadMissing('vendor');

        return $this->router->send(
            $payout->workspace_id,
            NotificationEvent::PayoutReleased,
            fn (string $locale): array => NotificationRenderer::render('payout_released', [
                'number' => $payout->number,
                'amount' => MoneyFormatter::format($payout->amount_minor, $payout->currency, $locale),
                'vendor' => $payout->vendor->name,
                'reference' => (string) $payout->paid_reference,
                'user' => UserRef::name($actor),
            ]),
            ['type' => 'payout_released', 'id' => $payout->id],
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.buttons.open_payout'), 'url' => route('payouts.show', $payout->id)]],
            audience: array_values(array_unique([$payout->requested_by, ...$this->ownerIds($payout->workspace_id)])),
        );
    }

    public function guardSettingsChanged(int $workspaceId, User $actor, string $changes, bool $loosened, ?GuardAlert $alert): int
    {
        return $this->router->send(
            $workspaceId,
            NotificationEvent::GuardSettingsChanged,
            fn (): array => NotificationRenderer::render('guard_settings_changed', ['user' => UserRef::name($actor), 'changes' => $changes]),
            ['type' => 'guard_settings', 'id' => $alert !== null ? $alert->id : $workspaceId],
            forced: $loosened,
            buttons: fn (): array => self::withAcknowledge([['text' => (string) __('payflow.telegram.open_settings'), 'url' => route('settings.guard')]], $alert),
            critical: $loosened,
            alertId: $alert?->id,
        );
    }

    public function guardRoleGranted(Workspace $workspace, User $actor, User $member, WorkspaceRole $role, ?CarbonInterface $eligibleFrom, ?GuardAlert $alert): int
    {
        $audience = $this->privilegedIds($workspace->id);

        return $this->router->send(
            $workspace->id,
            NotificationEvent::GuardRoleGranted,
            fn (string $locale): array => NotificationRenderer::render('guard_role_granted', [], [
                NotificationRenderer::line('guard_role_granted.body', [
                    'actor' => UserRef::name($actor),
                    'member' => UserRef::name($member),
                    'role' => $role->label(),
                    'eligible_from' => $eligibleFrom !== null ? self::localTime($eligibleFrom, $workspace) : '—',
                ]),
                NotificationRenderer::line('guard_role_granted.warning'),
            ]),
            ['type' => 'guard_role', 'id' => $alert !== null ? $alert->id : $member->id],
            forced: true,
            buttons: fn (): array => self::withAcknowledge([['text' => (string) __('payflow.telegram.buttons.open_members'), 'url' => route('settings.members')]], $alert),
            onlyUserIds: array_values(array_diff($audience, [$member->id])),
            alertId: $alert?->id,
        );
    }

    public function guardRoleRevoked(Workspace $workspace, User $actor, User $member, WorkspaceRole $role, ?GuardAlert $alert): int
    {
        return $this->router->send(
            $workspace->id,
            NotificationEvent::GuardRoleGranted,
            fn (): array => NotificationRenderer::render('guard_role_revoked', [
                'actor' => UserRef::name($actor),
                'member' => UserRef::name($member),
                'role' => $role->label(),
            ]),
            ['type' => 'guard_role_revoked', 'id' => $alert !== null ? $alert->id : $member->id],
            buttons: fn (): array => self::withAcknowledge([['text' => (string) __('payflow.telegram.buttons.open_members'), 'url' => route('settings.members')]], $alert),
            onlyUserIds: array_values(array_diff($this->ownerIds($workspace->id), [$actor->id])),
        );
    }

    public function receivingDetailsChangeRequested(ReceivingDetailsVersion $version, string $last4, ?GuardAlert $alert = null): int
    {
        $workspace = Workspace::query()->findOrFail($version->workspace_id);

        return $this->router->send(
            $version->workspace_id,
            NotificationEvent::ReceivingDetailsChange,
            fn (): array => NotificationRenderer::render('receiving_details_change_requested', [
                'user' => UserRef::name($version->requester),
                'last4' => $last4,
                'effective_at' => $version->effective_at !== null ? self::localTime($version->effective_at, $workspace) : self::localTime($version->requested_at->copy()->addDay(), $workspace),
            ]),
            ['type' => 'receiving_details', 'id' => $version->id],
            forced: true,
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.buttons.review_in_app'), 'url' => route('settings.collections')]],
            onlyUserIds: $this->privilegedIds($version->workspace_id),
            alertId: $alert?->id,
        );
    }

    public function receivingDetailsChanged(ReceivingDetailsVersion $version, ?GuardAlert $alert): int
    {
        return $this->router->send(
            $version->workspace_id,
            NotificationEvent::ReceivingDetailsChange,
            fn (): array => NotificationRenderer::render('receiving_details_changed', ['user' => UserRef::name($version->requester)]),
            ['type' => 'receiving_details_active', 'id' => $version->id],
            forced: true,
            buttons: fn (): array => self::withAcknowledge([['text' => (string) __('payflow.telegram.open_settings'), 'url' => route('settings.collections')]], $alert),
            onlyUserIds: $this->ownerIds($version->workspace_id),
            alertId: $alert?->id,
        );
    }

    public function trustedContactChanged(TrustedContact $contact, User $actor, ?GuardAlert $alert = null): int
    {
        return $this->router->send(
            $contact->workspace_id,
            NotificationEvent::GuardSettingsChanged,
            fn (): array => NotificationRenderer::render('trusted_contact_changed', [
                'user' => UserRef::name($actor),
                'status' => (string) __('payflow.trusted.statuses.'.$contact->status->value),
            ]),
            ['type' => 'trusted_contact', 'id' => $contact->id],
            forced: true,
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_settings'), 'url' => route('settings.guard')]],
            onlyUserIds: $this->ownerIds($contact->workspace_id),
            alertId: $alert?->id,
        );
    }

    public function insufficientApprovers(PayoutRequest $payout, int $required, int $eligible): int
    {
        return $this->router->send(
            $payout->workspace_id,
            NotificationEvent::PayoutPendingApproval,
            fn (): array => NotificationRenderer::render('insufficient_approvers', [
                'number' => $payout->number,
                'required' => $required,
                'eligible' => $eligible,
            ]),
            ['type' => 'insufficient_approvers', 'id' => $payout->id],
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_settings'), 'url' => route('settings.guard')]],
            onlyUserIds: $this->ownerIds($payout->workspace_id),
        );
    }

    public function secondFactorMissing(int $workspaceId, User $user): int
    {
        return $this->router->send(
            $workspaceId,
            NotificationEvent::GuardSettingsChanged,
            fn (): array => NotificationRenderer::render('second_factor_missing', ['user' => UserRef::name($user)]),
            ['type' => 'second_factor', 'id' => $user->id],
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.buttons.open_security'), 'url' => route('security.edit')]],
            onlyUserIds: array_values(array_unique([$user->id, ...$this->ownerIds($workspaceId)])),
        );
    }

    public function trustedConfirmationExpired(TrustedConfirmation $confirmation): int
    {
        $snapshot = $confirmation->snapshot ?? [];

        return $this->router->send(
            $confirmation->workspace_id,
            NotificationEvent::PayoutDecided,
            fn (): array => NotificationRenderer::render('trusted_confirmation_expired', [
                'contact' => $confirmation->contact->name,
                'subject' => (string) ($snapshot['number'] ?? $snapshot['vendor'] ?? ('#'.$confirmation->subject_id)),
            ]),
            ['type' => 'trusted_confirmation', 'id' => $confirmation->id],
            onlyUserIds: [$confirmation->created_by],
        );
    }

    public function bankChangeDecided(VendorBankAccount $account, User $actor, bool $verified): int
    {
        return $this->router->send(
            $account->workspace_id,
            NotificationEvent::BankChangeRequested,
            fn (): array => NotificationRenderer::render($verified ? 'bank_change_verified' : 'bank_change_rejected', [
                'vendor' => $account->vendor->name,
                'last4' => $account->last4,
                'user' => UserRef::name($actor),
            ]),
            ['type' => 'vendor_bank_account_decision', 'id' => $account->id],
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.buttons.review_in_app'), 'url' => route('guard.bank-changes.show', $account->id)]],
            onlyUserIds: $account->requested_by_user_id === $actor->id ? [] : [$account->requested_by_user_id],
        );
    }

    /** @return list<int> */
    public function ownerIds(int $workspaceId): array
    {
        return array_values(array_map('intval', DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $workspaceId)
            ->where('workspace_user.role', WorkspaceRole::Owner->value)
            ->whereNull('users.pseudonymised_at')
            ->pluck('workspace_user.user_id')
            ->all()));
    }

    /** @return list<int> */
    public function privilegedIds(int $workspaceId): array
    {
        $roles = array_map(fn (WorkspaceRole $role): string => $role->value, WorkspaceRole::guardPrivileged());

        return array_values(array_map('intval', DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $workspaceId)
            ->whereIn('workspace_user.role', $roles)
            ->whereNull('users.pseudonymised_at')
            ->pluck('workspace_user.user_id')
            ->all()));
    }

    /** @return list<int> */
    private function eligibleIds(int $workspaceId, string $ability, ?int $except): array
    {
        return array_values(array_map(
            fn (EligibleMember $member): int => $member->user->id,
            array_filter($this->eligibility->members($workspaceId, $ability), fn (EligibleMember $member): bool => $member->eligible() && $member->user->id !== $except),
        ));
    }

    /** @return list<int> */
    private function decidedIds(PayoutRequest $payout): array
    {
        return array_values(array_map('intval', PayoutApproval::query()->forWorkspace($payout->workspace_id)
            ->where('payout_request_id', $payout->id)
            ->where('approval_round', $payout->approval_round)
            ->whereNull('invalidated_at')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all()));
    }

    /**
     * @param  list<array{text: string, url?: string, callback?: string}>  $buttons
     * @return list<array{text: string, url?: string, callback?: string}>
     */
    private static function withAcknowledge(array $buttons, ?GuardAlert $alert): array
    {
        if ($alert !== null) {
            $buttons[] = ['text' => (string) __('payflow.telegram.buttons.acknowledge'), 'callback' => 'pf.ack:'.$alert->id];
        }

        return $buttons;
    }

    public static function localTime(CarbonInterface $instant, Workspace $workspace): string
    {
        return $instant->copy()->setTimezone(WorkspaceClock::zone($workspace->timezone))->format('Y-m-d H:i');
    }
}
