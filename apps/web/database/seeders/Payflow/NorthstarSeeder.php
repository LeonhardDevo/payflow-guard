<?php

declare(strict_types=1);

namespace Database\Seeders\Payflow;

use App\Domain\Analytics\Risk\RiskRecomputer;
use App\Domain\Collections\Dispatch\ReminderDispatcher;
use App\Domain\Collections\Enums\ReminderKind;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Enums\TemplateKey;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Models\MessageTemplate;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Collections\Models\ReminderPolicyStep;
use App\Domain\Collections\Planning\SendSlot;
use App\Domain\Collections\Templates\DefaultTemplates;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Bank\GuardKeys;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Enums\ConfirmationMode;
use App\Domain\Guard\Enums\ReceivingDetailsStatus;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceSource;
use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Receivables\Enums\ToneCeiling;
use App\Domain\Receivables\Invoices\InvoiceDrafts;
use App\Domain\Receivables\Invoices\InvoiceIssuer;
use App\Domain\Receivables\Invoices\InvoiceLifecycle;
use App\Domain\Receivables\Invoices\InvoiceSettlement;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Tokens;
use App\Enums\AiChannel;
use App\Enums\WorkspaceRole;
use App\Knowledge\DocumentService;
use App\Models\AiQuery;
use App\Models\PaymentAccount;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NorthstarSeeder
{
    public const array CLIENTS = [
        'helios' => [
            'name' => 'Helios Media Group', 'legal_name' => 'Helios Media Group Inc.', 'contact_name' => 'Rachel Moore', 'email' => 'ap@helios-media.example',
            'country' => 'US', 'currency' => 'USD', 'locale' => 'en', 'terms' => 30, 'policy' => 'standard', 'ceiling' => 'none', 'owner' => 'laura',
            'late_fee' => ['percent_monthly', 150, 10, 1500],
            'history' => [[21, 8200], [14, 4500], [30, 4500], [9, 9600], [25, 4500], [18, 11800], [12, 4500], [18, 4500]],
        ],
        'orbit' => [
            'name' => 'Orbit Fintech', 'legal_name' => 'Orbit Fintech Inc.', 'contact_name' => 'Sanjay Patel', 'email' => 'payables@orbit-fintech.example',
            'cc' => ['cfo@orbit-fintech.example'], 'country' => 'US', 'currency' => 'USD', 'locale' => 'en', 'terms' => 45, 'policy' => 'gentle',
            'ceiling' => 'neutral', 'owner' => 'anna', 'timezone' => 'America/New_York',
            'history' => [[0, 15000], [5, 18750], [2, 12500], [11, 18750], [0, 9000], [7, 16250]],
        ],
        'brightpath' => [
            'name' => 'Brightpath Health', 'legal_name' => 'Brightpath Health LLC', 'contact_name' => 'Emily Carter', 'email' => 'billing@brightpath.example',
            'country' => 'US', 'currency' => 'USD', 'locale' => 'en', 'terms' => 14, 'policy' => 'standard', 'ceiling' => 'none', 'owner' => 'laura',
            'history' => [[0, 9800], [0, 9800], [2, 9800], [0, 9800], [1, 9800], [0, 9800], [0, 9800], [3, 9800], [0, 9800], [0, 9800]],
        ],
        'greenline' => [
            'name' => 'Greenline Fitness', 'legal_name' => 'Greenline Fitness Ltd.', 'contact_name' => 'Tom Becker', 'email' => 'accounts@greenline.example',
            'country' => 'CA', 'currency' => 'USD', 'locale' => 'en', 'terms' => 14, 'policy' => 'standard', 'ceiling' => 'none', 'owner' => 'laura',
            'history' => [[0, 2350], [1, 1800], [0, 2350], [0, 1200]],
        ],
        'baltic' => [
            'name' => 'Балтийская логистика', 'legal_name' => 'SIA "Baltic Logistics"', 'contact_name' => 'Сергей Кузнецов', 'email' => 'buh@baltlog.example',
            'country' => 'LV', 'currency' => 'EUR', 'locale' => 'ru', 'terms' => 14, 'policy' => 'standard', 'ceiling' => 'none', 'owner' => 'anna',
            'late_fee' => ['percent_daily', 10, 0, 1000],
            'history' => [[2, 3850], [6, 3850], [0, 3850], [9, 3850], [4, 3850]],
        ],
        'alba' => [
            'name' => 'Студия Альба', 'legal_name' => 'Studio Alba d.o.o.', 'contact_name' => 'Милица Йованович', 'email' => 'finance@studio-alba.example',
            'country' => 'RS', 'currency' => 'EUR', 'locale' => 'ru', 'terms' => 30, 'policy' => 'standard', 'ceiling' => 'none', 'owner' => 'laura',
            'history' => [[3, 2700], [0, 4100], [8, 3900]],
        ],
        'kraft' => [
            'name' => 'Kraft & Hale Architects', 'legal_name' => 'Kraft & Hale LLP', 'contact_name' => 'Daniel Hale', 'email' => 'office@krafthale.example',
            'country' => 'US', 'currency' => 'USD', 'locale' => 'en', 'terms' => 30, 'policy' => 'standard', 'ceiling' => 'none', 'owner' => 'anna',
            'history' => [[0, 5400]],
        ],
        'nordlicht' => [
            'name' => 'Nordlicht Apps', 'legal_name' => 'Nordlicht Apps GmbH', 'contact_name' => 'Jonas Weber', 'email' => 'rechnung@nordlicht-apps.example',
            'country' => 'DE', 'currency' => 'EUR', 'locale' => 'en', 'terms' => 30, 'policy' => 'standard', 'ceiling' => 'none', 'owner' => 'anna',
            'history' => [[0, 5400], [2, 5400], [0, 5400], [1, 5400]],
        ],
    ];

    public const string RECEIVING_DETAILS = 'Northstar Digital SIA · IBAN LV09DEMO0551043018275 · BIC DEMOLV2X · Baltic Demo Bank';

    private SeedContext $context;

    private Workspace $workspace;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, ReminderPolicy> */
    private array $policies = [];

    public function run(SeedContext $context): ?Workspace
    {
        $workspace = PayflowDemoSeeder::northstar();

        if ($workspace === null) {
            return null;
        }

        if (PayflowDemoSeeder::alreadySeeded($workspace)) {
            self::demotePlatformAdmin($workspace);

            return $workspace;
        }

        $this->context = $context;
        $this->workspace = $workspace;

        return app(CurrentWorkspace::class)->runAs($workspace, function () use ($workspace): Workspace {
            $context = $this->context;
            $context->travel($context->at(-460, '09:00'));
            $this->workspaceAndUsers();
            $this->settings();
            $this->policies();
            $this->paymentAccount();
            $this->clients();
            $this->history();
            $this->anchors();
            $context->restore();
            $this->documents();
            $this->aiQueries();
            WorkspaceProvisioner::settingsFor($workspace)->forceFill(['next_invoice_seq' => 155, 'next_payout_seq' => 93])->save();
            app(RiskRecomputer::class)->workspace($workspace, $context->realNow);
            DB::table('outbox_messages')->where('workspace_id', $workspace->id)->whereIn('topic', ['email.send', 'telegram.notify'])->delete();

            return $workspace;
        }, WorkspaceRole::Owner);
    }

    private function workspaceAndUsers(): void
    {
        $this->workspace->forceFill([
            'name' => 'Northstar Digital',
            'timezone' => SeedContext::TIMEZONE,
            'currency' => 'USD',
            'country' => 'LV',
            'locale' => 'en',
        ])->save();

        $owner = User::query()->where('email', 'demo@payflow.test')->firstOrFail();
        $owner->forceFill(['name' => 'Anna Petrova'])->save();
        $this->users['anna'] = $owner;
        $this->users['mark'] = $this->user('finance@payflow.test', 'Mark Ellis', 'en', WorkspaceRole::Finance, -400);
        $this->users['dmitry'] = $this->user('approver@payflow.test', 'Dmitry Sokolov', 'ru', WorkspaceRole::Approver, -400);
        $this->users['olga'] = $this->user('ops@payflow.test', 'Olga Ivanova', 'ru', WorkspaceRole::Admin, -300);
        $this->users['laura'] = $this->user('pm@payflow.test', 'Laura Chen', 'en', WorkspaceRole::Member, null);

        self::demotePlatformAdmin($this->workspace);

        DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->where('user_id', $owner->id)
            ->update(['guard_eligible_from' => $this->workspace->created_at]);

        foreach (['anna' => 700000001, 'mark' => 700000002, 'dmitry' => 700000003, 'olga' => 700000005] as $key => $telegramId) {
            TelegramAccount::query()->updateOrCreate(['user_id' => $this->users[$key]->id], [
                'workspace_id' => $this->workspace->id,
                'telegram_user_id' => $telegramId,
                'chat_id' => $telegramId,
                'username' => null,
                'language_code' => $this->users[$key]->locale,
                'linked_at' => $this->context->at(-200),
            ]);
        }
    }

    public static function demotePlatformAdmin(Workspace $workspace): void
    {
        $admin = User::query()->where('email', 'admin@payflow.test')->first();

        if ($admin !== null) {
            DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $admin->id)
                ->update(['role' => WorkspaceRole::Member->value, 'guard_eligible_from' => null]);
        }
    }

    private function user(string $email, string $name, string $locale, WorkspaceRole $role, ?int $eligibleOffset): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->forceFill(['name' => $name, 'password' => 'password', 'locale' => $locale, 'email_verified_at' => $this->context->at(-460)])->save();
        }

        if (! $user->belongsToWorkspace($this->workspace)) {
            $this->workspace->addMember($user, $role);
        }

        DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->where('user_id', $user->id)->update([
            'role' => $role->value,
            'guard_eligible_from' => $eligibleOffset !== null ? $this->context->at($eligibleOffset) : null,
        ]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->saveQuietly();

        return $user;
    }

    private function settings(): void
    {
        $settings = WorkspaceProvisioner::settingsFor($this->workspace);
        $settings->forceFill([
            'invoice_prefix' => 'NS-',
            'payout_prefix' => 'PO-',
            'default_payment_terms_days' => 14,
            'send_window_start' => '09:00',
            'send_window_end' => '17:00',
            'business_days_only' => true,
            'first_contact_max_per_hour' => 50,
            'sender_display_name' => 'Northstar Digital — Finance',
            'reply_to_email' => 'finance@northstar.example',
            'privacy_contact_email' => 'finance@northstar.example',
            'late_fee_type' => LateFeeType::PercentMonthly,
            'late_fee_rate_bp' => 100,
            'late_fee_grace_days' => 7,
            'late_fee_cap_bp' => 1000,
            'company_legal_name' => 'Northstar Digital SIA',
            'company_address' => "Brīvības iela 68\nRīga, LV-1011\nLatvia",
            'company_tax_id' => 'LV40003999999',
            'company_email' => 'finance@northstar.example',
            'digest_enabled' => true,
            'digest_hour' => 9,
            'approval_thresholds' => [
                'USD' => ['auto_approve_below_minor' => 50000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => 100000],
                'EUR' => ['auto_approve_below_minor' => 50000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => 100000],
            ],
            'cooling_off_hours_medium' => 24,
            'cooling_off_hours_high' => 48,
            'allow_telegram_approvals' => true,
            'escalate_pending_after_hours' => 24,
            'guard_trust_delay_hours' => 72,
            'require_second_factor' => false,
            'solo_release_delay_hours' => 24,
        ])->save();

        $details = new ReceivingDetailsVersion;
        $details->forceFill([
            'workspace_id' => $this->workspace->id,
            'version' => 1,
            'details' => self::RECEIVING_DETAILS,
            'details_hash' => self::detailsHash(self::RECEIVING_DETAILS),
            'status' => ReceivingDetailsStatus::Active,
            'requested_by' => $this->users['anna']->id,
            'requested_at' => $this->context->at(-400),
            'confirmation_mode' => ConfirmationMode::SecondPerson,
            'confirmed_by' => $this->users['dmitry']->id,
            'confirmed_at' => $this->context->at(-399),
            'effective_at' => $this->context->at(-399),
        ])->save();
        $settings->forceFill(['active_receiving_details_id' => $details->id])->save();
    }

    public static function detailsHash(string $details): string
    {
        return GuardKeys::mac(GuardKeys::RECEIVING_DETAILS, $details);
    }

    private function policies(): void
    {
        $settings = WorkspaceProvisioner::settingsFor($this->workspace);
        $standard = ReminderPolicy::query()->with('steps')->findOrFail($settings->default_reminder_policy_id);
        $standard->forceFill(['name' => 'Standard cadence', 'description' => 'Default cadence for all clients.'])->save();
        $this->policies['standard'] = $standard;

        $gentle = new ReminderPolicy;
        $gentle->forceFill([
            'workspace_id' => $this->workspace->id,
            'name' => 'Gentle — key accounts',
            'description' => 'Softer cadence for long-standing key accounts.',
            'is_default' => false,
            'max_reminders' => 4,
            'min_interval_days' => 5,
            'escalate_after_days' => 45,
        ])->save();

        foreach ([0, 7, 14, 28] as $index => $offset) {
            $step = new ReminderPolicyStep;
            $step->forceFill([
                'workspace_id' => $this->workspace->id,
                'policy_id' => $gentle->id,
                'position' => $index + 1,
                'offset_days' => $offset,
                'tone' => StepTone::Auto,
            ])->save();
        }

        $this->policies['gentle'] = $gentle->load('steps');

        $template = MessageTemplate::query()->where('key', TemplateKey::ReminderNeutral->value)->where('locale', 'ru')->first();

        if ($template !== null) {
            $default = DefaultTemplates::text(TemplateKey::ReminderNeutral, 'ru');
            $template->forceFill([
                'subject' => $default['subject'],
                'body_markdown' => str_replace(
                    "\n\nС уважением,",
                    "\n\nЕсли удобнее, можем выставить счёт на оставшуюся сумму частями.\n\nС уважением,",
                    $default['body'],
                ),
                'is_customized' => true,
                'updated_by' => $this->users['anna']->id,
            ])->save();
        }
    }

    private function paymentAccount(): void
    {
        $account = PaymentAccount::query()->firstOrNew(['workspace_id' => $this->workspace->id, 'driver' => 'fake']);
        $account->forceFill([
            'credentials' => [
                'external_account_id' => 'acct_fake_northstar0001',
                'endpoint_key' => Tokens::seeded('northstar-endpoint', 32),
                'webhook_secret' => 'whsec_fake_'.Tokens::seeded('northstar-secret', 32),
            ],
            'connected_at' => $this->context->at(-420),
        ])->save();
    }

    private function clients(): void
    {
        foreach (self::CLIENTS as $key => $data) {
            $client = new Client;
            $fee = $data['late_fee'] ?? null;
            $client->forceFill([
                'workspace_id' => $this->workspace->id,
                'name' => $data['name'],
                'legal_name' => $data['legal_name'],
                'contact_name' => $data['contact_name'],
                'email' => $data['email'],
                'cc_emails' => $data['cc'] ?? [],
                'country' => $data['country'],
                'timezone' => $data['timezone'] ?? null,
                'locale' => $data['locale'],
                'currency' => $data['currency'],
                'payment_terms_days' => $data['terms'],
                'reminder_policy_id' => $data['policy'] === 'gentle' ? $this->policies['gentle']->id : null,
                'tone_ceiling' => ToneCeiling::from($data['ceiling']),
                'late_fee_type' => $fee !== null ? LateFeeType::from($fee[0]) : null,
                'late_fee_rate_bp' => $fee[1] ?? null,
                'late_fee_grace_days' => $fee[2] ?? null,
                'late_fee_cap_bp' => $fee[3] ?? null,
                'owner_user_id' => $this->users[$data['owner']]->id,
                'created_by' => $this->users['anna']->id,
            ])->save();
            $this->clients[$key] = $client;
        }
    }

    private function history(): void
    {
        $plan = [];

        foreach (self::CLIENTS as $key => $data) {
            $count = count($data['history']);
            $anchor = $key === 'helios' ? $this->context->date(-95) : $this->context->date(-40 - $data['terms'] - 30);

            foreach ($data['history'] as $index => [$daysLate, $amount]) {
                $issue = CarbonImmutable::parse($anchor)->subDays(30 * ($count - 1 - $index))->toDateString();
                $plan[] = ['client' => $key, 'issue' => $issue, 'late' => $daysLate, 'amount' => $amount, 'method' => $index % 2 === 0 ? 'bank_transfer' : 'payment_link'];
            }
        }

        usort($plan, fn (array $a, array $b): int => [$a['issue'], $a['client']] <=> [$b['issue'], $b['client']]);
        $sequence = [];

        foreach ($plan as $row) {
            $year = (int) substr($row['issue'], 0, 4);
            $sequence[$year] = ($sequence[$year] ?? 0) + 1;
            $number = 'NS-'.$year.'-'.str_pad((string) $sequence[$year], 4, '0', STR_PAD_LEFT);
            $client = $this->clients[$row['client']];
            $due = CarbonImmutable::parse($row['issue'])->addDays((int) $client->payment_terms_days)->toDateString();
            $localized = CarbonImmutable::parse($row['issue'])->locale($client->locale);
            $month = $localized instanceof CarbonImmutable ? $localized->isoFormat('MMMM YYYY') : substr($row['issue'], 0, 7);
            $description = $client->locale === 'ru' ? 'Услуги — '.$month : 'Services — '.$month;

            $invoice = $this->issue($client, $number, [[$description, '1', $row['amount'] * 100]], $row['issue'], $due, 'history-'.$number);
            $paidAt = CarbonImmutable::parse(CarbonImmutable::parse($due)->addDays($row['late'])->toDateString().' 11:00', SeedContext::TIMEZONE)->utc();
            $this->pay($invoice, $row['amount'] * 100, $paidAt, $row['method'] === 'payment_link' ? PaymentMethod::PaymentLink : PaymentMethod::BankTransfer);
        }
    }

    /** @param  list<array{0: string, 1: string, 2: int}>  $items */
    private function issue(Client $client, ?string $number, array $items, string $issue, string $due, string $tokenSeed, ?string $po = null, bool $send = true): Invoice
    {
        $sentAt = CarbonImmutable::parse($issue.' 09:30', SeedContext::TIMEZONE)->utc();
        $this->context->travel($sentAt);
        $invoice = app(InvoiceDrafts::class)->create($this->workspace, $this->users['mark'], [
            'client_id' => $client->id,
            'number' => $number,
            'currency' => $client->currency,
            'locale' => $client->locale,
            'issue_date' => $issue,
            'due_date' => $due,
            'po_number' => $po,
            'items' => array_map(fn (array $item): array => [
                'description' => $item[0],
                'quantity' => $item[1],
                'unit_price_minor' => $item[2],
                'tax_rate_bp' => 0,
            ], $items),
        ], InvoiceSource::Manual, Tokens::seeded('northstar|'.$tokenSeed, 40));

        if (! $send) {
            return $invoice;
        }

        app(InvoiceIssuer::class)->markSent($invoice, $this->users['mark'], $sentAt);
        $invoice->forceFill(['first_contact_confirmed_at' => $sentAt, 'first_contact_confirmed_by' => $this->users['anna']->id])->save();

        return $invoice->refresh();
    }

    private function pay(Invoice $invoice, int $amount, CarbonImmutable $paidAt, PaymentMethod $method): Payment
    {
        $this->context->travel($paidAt);
        $payment = new Payment;
        $payment->forceFill([
            'workspace_id' => $invoice->workspace_id,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'amount_minor' => $amount,
            'currency' => $invoice->currency,
            'method' => $method,
            'status' => PaymentStatus::Succeeded,
            'provider' => $method === PaymentMethod::PaymentLink ? PaymentProvider::Fake : PaymentProvider::Manual,
            'provider_payment_id' => $method === PaymentMethod::PaymentLink ? 'pi_fake_'.Tokens::seeded('pay|'.$invoice->id.'|'.$amount, 24) : null,
            'paid_at' => $paidAt,
            'reference' => $method === PaymentMethod::BankTransfer ? 'TRF-'.$invoice->label() : null,
            'recorded_by' => $method === PaymentMethod::BankTransfer ? $this->users['mark']->id : null,
        ])->save();

        app(InvoiceTimeline::class)->record($invoice, InvoiceEventType::PaymentReceived, [
            'payment_id' => $payment->id,
            'amount_minor' => $amount,
            'method' => $method->value,
        ], $method === PaymentMethod::BankTransfer ? $this->users['mark'] : null, $method === PaymentMethod::PaymentLink ? EventActorType::Provider : null);

        app(InvoiceSettlement::class)->settle($invoice, $method === PaymentMethod::BankTransfer ? $this->users['mark'] : null);

        return $payment;
    }

    private function anchors(): void
    {
        $c = $this->clients;
        $standard = $this->policies['standard']->steps->keyBy('offset_days');
        $gentle = $this->policies['gentle']->steps->keyBy('offset_days');

        $helios = $this->issue($c['helios'], 'NS-2026-0118', [['Website redesign — milestone 2 (design system & page templates)', '1', 1240000]], $this->context->date(-82), $this->context->date(-52), 'NS-2026-0118');
        $this->view($helios, -80);
        $tones = [-3 => [-55, Tone::Friendly], 0 => [-52, Tone::Friendly], 4 => [-48, Tone::Neutral], 9 => [-43, Tone::Firm], 14 => [-38, Tone::Firm], 21 => [-31, Tone::Firm]];
        $this->sentReminders($helios, $standard, $tones);
        $this->reminder($helios, $standard->get(30), Tone::Firm, ReminderStatus::Skipped, $this->context->at(-22), ReminderSkipReason::Superseded);
        $this->scheduled($helios, $standard->get(45), 1, StepTone::Final);
        app(GuardAlerts::class)->ensure($this->workspace->id, AlertType::CollectionsEscalation, AlertSubjectType::Invoice, $helios->id, 'esc:'.$helios->id, ['number' => $helios->label(), 'client' => $c['helios']->name, 'days' => 52]);

        $retainer = $this->issue($c['helios'], 'NS-2026-0131', [['Monthly retainer — September', '1', 450000]], $this->context->date(-61), $this->context->date(-31), 'NS-2026-0131');
        $this->view($retainer, -60);
        $this->sentReminders($retainer, $standard, [-3 => [-34, Tone::Friendly], 0 => [-31, Tone::Friendly], 4 => [-27, Tone::Neutral], 9 => [-22, Tone::Firm], 14 => [-17, Tone::Firm]]);
        $this->pay($retainer, 150000, $this->context->at(-20, '11:00'), PaymentMethod::BankTransfer);
        $failed = $this->reminder($retainer, $standard->get(21), Tone::Firm, ReminderStatus::Failed, $this->context->at(-10, '09:12'));
        $failed->forceFill(['failed_at' => $this->context->at(-10, '09:40'), 'error' => '452 4.2.2 Mailbox full', 'delivery_attempts' => 8])->save();
        $retainer->forceFill(['reminders_sent_count' => 6, 'last_reminder_at' => $this->context->at(-10, '09:12')])->save();
        app(InvoiceTimeline::class)->record($retainer, InvoiceEventType::ReminderFailed, ['reminder_id' => $failed->id, 'tone' => 'firm'], actorType: EventActorType::System, at: $this->context->at(-10, '09:40'));
        app(GuardAlerts::class)->ensure($this->workspace->id, AlertType::ReminderFailed, AlertSubjectType::Invoice, $retainer->id, 'rf:'.$failed->id, ['number' => $retainer->label(), 'client' => $c['helios']->name, 'reminder_id' => $failed->id]);

        $orbit = $this->issue($c['orbit'], 'NS-2026-0137', [['Mobile app — sprints 14–15', '2', 937500]], $this->context->date(-55), $this->context->date(-10), 'NS-2026-0137', 'PO-77812');
        $this->view($orbit, -54);
        $this->sentReminders($orbit, $gentle, [0 => [-10, Tone::Friendly], 7 => [-3, Tone::Friendly]], 'America/New_York');
        $this->scheduled($orbit, $gentle->get(14), 4, StepTone::Auto);

        $alba = $this->issue($c['alba'], 'NS-2026-0142', [['UX-аудит', '1', 270000], ['Интерактивный прототип', '1', 350000]], $this->context->date(-40), $this->context->date(-10), 'NS-2026-0142');
        $this->view($alba, -39);
        $this->sentReminders($alba, $standard, [-3 => [-13, Tone::Friendly], 0 => [-10, Tone::Friendly]]);
        $this->scheduled($alba, $standard->get(4), -6, StepTone::Auto);
        $this->context->travel($this->context->at(-8, '14:20'));
        app(InvoiceLifecycle::class)->dispute($alba, $this->users['laura'], 'Клиент оспаривает объём прототипа: по SOW не хватает двух экранов');

        $baltic = $this->issue($c['baltic'], 'NS-2026-0145', [['Сопровождение интеграции — сентябрь', '35', 11000]], $this->context->date(-21), $this->context->date(-7), 'NS-2026-0145');
        $this->view($baltic, -20);
        $this->sentReminders($baltic, $standard, [-3 => [-10, Tone::Friendly], 0 => [-7, Tone::Friendly], 4 => [-3, Tone::Friendly]]);
        $this->scheduled($baltic, $standard->get(9), 2, StepTone::Auto);

        $this->issue($c['kraft'], 'NS-2026-0149', [['Brand website — phase 1', '1', 720000]], $this->context->date(-12), $this->context->date(18), 'NS-2026-0149');

        $patient = $this->issue($c['brightpath'], 'NS-2026-0150', [['Patient portal — sprint 22', '80', 12250]], $this->context->date(-10), $this->context->date(4), 'NS-2026-0150');
        $this->view($patient, -9);
        $this->scheduled($patient, $standard->get(-3), 1, StepTone::Auto);

        $landing = $this->issue($c['greenline'], 'NS-2026-0151', [['Landing page A/B tests', '1', 235000]], $this->context->date(-9), $this->context->date(5), 'NS-2026-0151');
        $this->view($landing, -8);

        $this->issue($c['nordlicht'], 'NS-2026-0152', [['Android release support', '1', 540000]], $this->context->date(-5), $this->context->date(25), 'NS-2026-0152');

        $tweaks = $this->issue($c['greenline'], 'NS-2026-0153', [['Analytics dashboard tweaks', '1', 120000]], $this->context->date(-14), $this->context->date(0), 'NS-2026-0153');
        $this->view($tweaks, -13);
        $this->pay($tweaks, 120000, $this->context->at(-1, '15:10'), PaymentMethod::PaymentLink);

        $previous = $this->issue($c['brightpath'], 'NS-2026-0154', [['Patient portal — sprint 21', '80', 12250]], $this->context->date(-24), $this->context->date(-10), 'NS-2026-0154');
        $this->view($previous, -23);
        $this->pay($previous, 980000, $this->context->at(-9, '11:00'), PaymentMethod::BankTransfer);

        $this->context->travel($this->context->at(0, '08:30'));
        $this->issue($c['orbit'], null, [['Mobile app — sprints 16–17', '2', 937500]], $this->context->date(0), $this->context->date(45), 'draft-orbit', null, false);
    }

    private function view(Invoice $invoice, int $offset): void
    {
        $at = $this->context->at($offset, '14:05');
        $invoice->forceFill(['first_viewed_at' => $at, 'last_viewed_at' => $at, 'view_count' => 1])->saveQuietly();
        app(InvoiceTimeline::class)->record($invoice, InvoiceEventType::Viewed, [], actorType: EventActorType::Client, at: $at);
    }

    /**
     * @param  Collection<int|string, ReminderPolicyStep>  $steps
     * @param  array<int, array{0: int, 1: Tone}>  $plan
     */
    private function sentReminders(Invoice $invoice, $steps, array $plan, string $timezone = SeedContext::TIMEZONE): void
    {
        $count = 0;
        $last = null;

        foreach ($plan as $offset => [$day, $tone]) {
            $spread = SendSlot::spread($invoice->public_token);
            $at = CarbonImmutable::parse($this->context->date($day).' 09:00', $timezone)->utc()->addMinutes($spread);
            $reminder = $this->reminder($invoice, $steps->get($offset), $tone, ReminderStatus::Sent, $at);
            $reminder->forceFill(['queued_at' => $at, 'sent_at' => $at->addMinutes(1), 'delivery_attempts' => 1, 'is_first_contact' => $count === 0])->save();
            app(InvoiceTimeline::class)->record($invoice, InvoiceEventType::ReminderSent, ['reminder_id' => $reminder->id, 'tone' => $tone->value], actorType: EventActorType::System, at: $at->addMinutes(1));
            $count++;
            $last = $at;
        }

        $invoice->forceFill(['reminders_sent_count' => $count, 'last_reminder_at' => $last])->save();
    }

    private function reminder(Invoice $invoice, ?ReminderPolicyStep $step, Tone $tone, ReminderStatus $status, CarbonImmutable $at, ?ReminderSkipReason $skip = null): Reminder
    {
        $date = CarbonImmutable::parse($at)->setTimezone(SeedContext::TIMEZONE);
        $suggestion = ToneContext::suggest($invoice, $date->toDateString(), StepTone::from($tone->value));
        $email = in_array($status, [ReminderStatus::Sent, ReminderStatus::Failed], true)
            ? app(ReminderDispatcher::class)->render($invoice, $tone, $date->startOfDay(), null)
            : null;

        $reminder = new Reminder;
        $reminder->forceFill([
            'workspace_id' => $invoice->workspace_id,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'policy_step_id' => $step?->id,
            'kind' => ReminderKind::Scheduled,
            'step_offset_days' => $step?->offset_days,
            'tone' => $tone,
            'tone_reasons' => $suggestion->reasons,
            'locale' => $invoice->locale,
            'scheduled_for' => $at,
            'status' => $status,
            'skip_reason' => $skip,
            'recipient_email' => $email !== null ? $invoice->client->email : null,
            'cc_emails' => $email !== null ? $invoice->client->cc_emails : [],
            'subject' => $email?->subject,
            'body_html' => $email?->html,
            'body_text' => $email?->text,
            'attempts' => $email !== null ? 1 : 0,
        ])->save();

        return $reminder;
    }

    private function scheduled(Invoice $invoice, ?ReminderPolicyStep $step, int $dayOffset, StepTone $stepTone): Reminder
    {
        $settings = WorkspaceProvisioner::settingsFor($this->workspace);
        $timezone = $invoice->client->timezone ?? SeedContext::TIMEZONE;
        $slot = SendSlot::next(
            $this->context->date($dayOffset),
            $settings->windowStart(),
            $settings->windowEnd(),
            $settings->business_days_only,
            $timezone,
            $dayOffset >= 0 ? $this->context->realNow : $this->context->at($dayOffset, '00:00'),
            SendSlot::spread($invoice->public_token),
        );
        $suggestion = ToneContext::suggest($invoice, CarbonImmutable::parse($slot)->setTimezone(SeedContext::TIMEZONE)->toDateString(), $stepTone);

        $reminder = new Reminder;
        $reminder->forceFill([
            'workspace_id' => $invoice->workspace_id,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'policy_step_id' => $step?->id,
            'kind' => ReminderKind::Scheduled,
            'step_offset_days' => $step?->offset_days,
            'tone' => $suggestion->tone,
            'tone_reasons' => $suggestion->reasons,
            'locale' => $invoice->locale,
            'scheduled_for' => $slot,
            'status' => ReminderStatus::Scheduled,
        ])->save();

        return $reminder;
    }

    private function documents(): void
    {
        $path = (string) config('payflow.seed_documents_path');
        $manifestPath = $path.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($manifestPath)) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $documents = is_array($manifest) && is_array($manifest['documents'] ?? null) ? $manifest['documents'] : [];
        $service = app(DocumentService::class);

        foreach ($documents as $entry) {
            if (! is_array($entry) || ! is_string($entry['file'] ?? null)) {
                continue;
            }

            $file = $path.DIRECTORY_SEPARATOR.$entry['file'];

            if (! is_file($file)) {
                continue;
            }

            $tags = array_values(array_filter((array) ($entry['tags'] ?? []), 'is_string'));
            $client = is_string($entry['client'] ?? null) ? Client::query()->where('name', $entry['client'])->first() : null;

            if ($client !== null) {
                $tags[] = 'client:'.$client->id;
            }

            $document = $service->upload($this->workspace, $this->users['anna'], new UploadedFile($file, $entry['file'], 'text/markdown', null, true), 'text/markdown', [
                'title' => is_string($entry['title'] ?? null) ? $entry['title'] : null,
                'department' => is_string($entry['department'] ?? null) ? $entry['department'] : null,
                'roles' => array_values(array_filter((array) ($entry['roles'] ?? []), 'is_string')),
                'tags' => $tags,
                'version' => is_string($entry['version'] ?? null) ? $entry['version'] : null,
                'effective_date' => is_string($entry['effective_date'] ?? null) ? $entry['effective_date'] : null,
            ]);

            if ($client !== null && ($entry['link_as_contract'] ?? false) === true) {
                $client->forceFill(['contract_document_id' => $document->id])->save();
            }
        }
    }

    private function aiQueries(): void
    {
        $rows = [
            ['Which invoice is the most overdue?', 'NS-2026-0118 for Helios Media Group is 52 days overdue with $12,400.00 outstanding.', 'en'],
            ['Какой штраф по договору с Балтийской логистикой?', 'По договору начисляется 0,1% от суммы долга за каждый день просрочки, но не более 10% от суммы счёта.', 'ru'],
            ['When should we remind Helios Media?', 'The next reminder for NS-2026-0118 is scheduled for tomorrow morning with a final tone.', 'en'],
            ['Approve PO-2026-0089', '', 'en'],
            ['What is our crypto refund policy?', '', 'en'],
        ];

        foreach ($rows as $index => [$question, $text, $locale]) {
            $this->aiQuery($index, $question, $text !== '' ? $text : null, $locale);
        }
    }

    private function aiQuery(int $index, string $question, ?string $answer, string $locale): void
    {
        AiQuery::query()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $locale === 'ru' ? $this->users['dmitry']->id : $this->users['anna']->id,
            'channel' => $index % 2 === 0 ? AiChannel::Web : AiChannel::Telegram,
            'question' => $question,
            'answer' => $answer,
            'refused' => $answer === null,
            'confidence' => $answer === null ? null : 0.82,
            'citations' => [],
            'tools_used' => $answer === null ? [] : ['invoices_overdue'],
            'latency_ms' => 420 + $index * 35,
            'provider' => 'mock',
            'model' => 'mock-1',
        ]);
    }
}
