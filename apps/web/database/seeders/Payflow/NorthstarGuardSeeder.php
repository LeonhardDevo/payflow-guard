<?php

declare(strict_types=1);

namespace Database\Seeders\Payflow;

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Bank\BankDetails;
use App\Domain\Guard\BankChanges\BankChangeService;
use App\Domain\Guard\Enums\ApprovalChannel;
use App\Domain\Guard\Enums\ApprovalDecision;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\StepUpMethod;
use App\Domain\Guard\Enums\VendorCategory;
use App\Domain\Guard\Enums\VerificationMethod;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\DecisionContext;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Guard\Vendors\VendorService;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class NorthstarGuardSeeder
{
    public const string PIXELFORGE_REQUEST = 'Hello Mark, due to an ongoing audit our company has moved to a new bank. Please make this week\'s payment urgently to the new account below — the old account is being closed today. The team is travelling, so please don\'t call the office; reply to this email instead. New details: PF Studio Payments UAB, IBAN LT58 3250 0677 4120 0519. Kind regards, Marta Nowak, PixelForge Studio. Mob. +370 600 41 977';

    private SeedContext $context;

    private Workspace $workspace;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Vendor> */
    private array $vendors = [];

    public function run(SeedContext $context, Workspace $workspace): void
    {
        if (Vendor::query()->forWorkspace($workspace)->withTrashed()->where('name', 'PixelForge Studio')->exists()) {
            return;
        }

        $this->context = $context;
        $this->workspace = $workspace;

        foreach (['anna' => 'demo@payflow.test', 'mark' => 'finance@payflow.test', 'dmitry' => 'approver@payflow.test', 'olga' => 'ops@payflow.test'] as $key => $email) {
            $this->users[$key] = User::query()->where('email', $email)->firstOrFail();
        }

        DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $this->users['anna']->id)
            ->update(['guard_eligible_from' => $context->at(-460, '09:00')]);

        app(CurrentWorkspace::class)->runAs($workspace, function (): void {
            $this->vendors();
            $this->accounts();
            $this->history();
            $this->openPayouts();
            $this->context->restore();
        }, WorkspaceRole::Owner);

        WorkspaceProvisioner::settingsFor($workspace)->forceFill(['next_payout_seq' => 93])->save();
        DB::table('outbox_messages')->where('workspace_id', $workspace->id)->whereIn('topic', ['email.send', 'telegram.notify'])->delete();
    }

    public static function number(SeedContext $context, int $seq): string
    {
        return 'PO-'.CarbonImmutable::parse($context->today)->format('Y').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function vendors(): void
    {
        $service = app(VendorService::class);
        $definitions = [
            'pixelforge' => [-240, ['name' => 'PixelForge Studio', 'legal_name' => 'PixelForge Studio sp. z o.o.', 'category' => VendorCategory::Contractor->value, 'email' => 'billing@pixelforge.example', 'phone' => '+48 22 555 01 47', 'country' => 'PL', 'default_currency' => 'EUR']],
            'ivan' => [-300, ['name' => 'Ivan Smirnov', 'legal_name' => 'IE Ivan Smirnov', 'category' => VendorCategory::Freelancer->value, 'email' => 'ivan@smirnov-dev.example', 'phone' => '+995 555 12 34 56', 'country' => 'GE', 'default_currency' => 'USD']],
            'cloudnest' => [-705, ['name' => 'CloudNest Hosting', 'category' => VendorCategory::Saas->value, 'email' => 'billing@cloudnest.example', 'country' => 'US', 'default_currency' => 'USD']],
            'qacrew' => [-400, ['name' => 'QA Crew OÜ', 'category' => VendorCategory::Contractor->value, 'email' => 'invoices@qacrew.example', 'phone' => '+372 5555 0199', 'country' => 'EE', 'default_currency' => 'EUR']],
            'copywrite' => [-505, ['name' => 'Copywrite Lab', 'legal_name' => 'Copywrite Lab LLC', 'category' => VendorCategory::Agency->value, 'email' => 'hello@copywritelab.example', 'country' => 'US', 'default_currency' => 'USD']],
        ];

        foreach ($definitions as $key => [$offset, $data]) {
            $this->context->travel($this->context->at($offset, '10:00'));
            $vendor = $service->create($this->workspace, $this->users['mark'], $data);
            $vendor->forceFill(['created_at' => $this->context->at($offset, '10:00')])->save();
            $this->vendors[$key] = $vendor;
        }

        $this->vendors['pixelforge']->forceFill(['phone_verified_at' => $this->context->at(-200, '11:00')])->save();
    }

    private function accounts(): void
    {
        $this->account('pixelforge', 1, ['scheme' => 'iban', 'account_holder_name' => 'PixelForge Studio sp. z o.o.', 'iban' => 'PL61109010140000071219812874', 'bank_name' => 'Vistula Demo Bank', 'bank_country' => 'PL', 'currency' => 'EUR'], 'mark', -240, 'dmitry', -239);
        $this->account('ivan', 1, ['scheme' => 'iban', 'account_holder_name' => 'Ivan Smirnov', 'iban' => 'GE44TB7741936582010047', 'bank_name' => 'Kura Demo Bank', 'bank_country' => 'GE', 'currency' => 'USD'], 'mark', -296, 'anna', -295);
        $this->account('cloudnest', 1, ['scheme' => 'us_ach', 'account_holder_name' => 'CloudNest Hosting', 'routing_number' => '123456780', 'account_number' => '000123454471', 'bank_name' => 'Hudson Demo Bank', 'bank_country' => 'US', 'currency' => 'USD'], 'mark', -701, 'anna', -700);
        $this->account('copywrite', 1, ['scheme' => 'us_ach', 'account_holder_name' => 'Copywrite Lab LLC', 'routing_number' => '123456780', 'account_number' => '7700112870', 'bank_name' => 'Hudson Demo Bank', 'bank_country' => 'US', 'currency' => 'USD'], 'mark', -501, 'anna', -500);
        $old = $this->account('qacrew', 1, ['scheme' => 'iban', 'account_holder_name' => 'QA Crew OÜ', 'iban' => 'EE222200221077345612', 'bank_name' => 'Tallinn Demo Bank', 'bank_country' => 'EE', 'currency' => 'EUR'], 'mark', -399, 'anna', -398);
        $old->forceFill(['status' => BankAccountStatus::Superseded, 'superseded_at' => $this->context->at(-26, '15:00')])->save();
        $this->account('qacrew', 2, ['scheme' => 'iban', 'account_holder_name' => 'QA Crew OÜ', 'iban' => 'EE537700771003819264', 'bank_name' => 'Tallinn Demo Bank', 'bank_country' => 'EE', 'currency' => 'EUR'], 'mark', -27, 'dmitry', -26, [
            'source' => BankChangeSource::VendorEmail,
            'requester_contact' => 'invoices@qacrew.example',
            'requester_email_domain' => 'qacrew.example',
            'risk_score' => 35,
            'risk_band' => RiskBand::Medium,
            'risk_signals' => [
                ['code' => 'request_via_email', 'points' => 20, 'params' => []],
                ['code' => 'pending_payout', 'points' => 15, 'params' => ['count' => 1]],
            ],
            'cooling_off_until' => $this->context->at(-26, '15:00')->addHours(24),
        ]);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array<string, mixed>  $overrides
     */
    private function account(string $vendorKey, int $version, array $input, string $requester, int $requestedOffset, string $verifier, int $verifiedOffset, array $overrides = []): VendorBankAccount
    {
        $vendor = $this->vendors[$vendorKey];
        $details = BankDetails::fromInput($input);
        $account = new VendorBankAccount;
        $account->forceFill([
            'workspace_id' => $this->workspace->id,
            'vendor_id' => $vendor->id,
            'version' => $version,
            'scheme' => $details->scheme,
            'account_holder_name' => $details->holderName,
            'iban' => $details->iban,
            'account_number' => $details->accountNumber,
            'routing_number' => $details->routingNumber,
            'swift_bic' => $details->swiftBic,
            'bank_name' => $details->bankName,
            'bank_country' => $details->bankCountry,
            'currency' => $details->currency,
            'last4' => $details->last4(),
            'fingerprint' => $details->fingerprint(),
            'status' => BankAccountStatus::Verified,
            'source' => BankChangeSource::Onboarding,
            'requested_by_user_id' => $this->users[$requester]->id,
            'requested_at' => $this->context->at($requestedOffset, '10:30'),
            'risk_score' => 0,
            'risk_band' => RiskBand::Low,
            'risk_signals' => [],
            'verification_method' => VerificationMethod::CallbackKnownPhone,
            'verification_checklist' => array_fill_keys(BankChangeService::CHECKLIST, true),
            'verified_by_user_id' => $this->users[$verifier]->id,
            'verified_at' => $this->context->at($verifiedOffset, '15:00'),
            'verification_note' => 'Called the number on file and confirmed the holder name and the last four digits.',
            ...$overrides,
        ])->save();
        $vendor->forceFill(['current_bank_account_id' => $account->id])->save();

        return $account;
    }

    private function history(): void
    {
        foreach ([[-181, 58], [-151, 63], [-121, 68], [-91, 74], [-61, 79], [-31, 85]] as [$offset, $seq]) {
            $this->released('cloudnest', $seq, 32000, 'USD', 'Hosting — monthly plan', $offset, auto: true, releasedBy: 'anna');
        }

        foreach ([[-152, 60, 200000], [-122, 65, 220000], [-92, 70, 220000], [-62, 76, 240000], [-32, 83, 220000]] as [$offset, $seq, $amount]) {
            $this->released('ivan', $seq, $amount, 'USD', 'Backend development — monthly retainer', $offset, auto: false, releasedBy: 'mark');
        }

        $rejected = $this->payoutRow('ivan', 88, 220000, 'USD', 'October — duplicate of IS-2026-09', -7, PayoutStatus::Rejected, [
            'vendor_invoice_number' => 'IS-2026-09',
            'rejected_at' => $this->context->at(-6, '11:20'),
        ]);
        $this->approvalRow($rejected, 'dmitry', ApprovalDecision::Rejected, -6, 'Duplicate of '.self::number($this->context, 83));
    }

    private function released(string $vendorKey, int $seq, int $amount, string $currency, string $description, int $createdOffset, bool $auto, string $releasedBy): void
    {
        $submitted = $this->context->at($createdOffset + 2, '10:00');
        $payout = $this->payoutRow($vendorKey, $seq, $amount, $currency, $description, $createdOffset, PayoutStatus::Released, [
            'submitted_at' => $submitted,
            'approved_at' => $auto ? $submitted : $this->context->at($createdOffset + 2, '16:00'),
            'required_approvals' => $auto ? 0 : 1,
            'approvals_count' => $auto ? 0 : 1,
            'auto_approved' => $auto,
            'auto_approve_basis' => $auto ? ['threshold' => 50000, 'cumulative_used' => 0, 'cumulative_cap' => 100000] : null,
            'release_requires_other_user' => $auto,
            'released_by' => $this->users[$releasedBy]->id,
            'released_at' => $this->context->at($createdOffset + 3, '12:00'),
            'paid_reference' => 'TRF-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'paid_on' => $this->context->date($createdOffset + 3),
        ]);

        if (! $auto) {
            $this->approvalRow($payout, 'dmitry', ApprovalDecision::Approved, $createdOffset + 2, null);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function payoutRow(string $vendorKey, int $seq, int $amount, string $currency, string $description, int $createdOffset, PayoutStatus $status, array $attributes): PayoutRequest
    {
        $vendor = $this->vendors[$vendorKey];
        $account = VendorBankAccount::query()->forWorkspace($this->workspace)->findOrFail($vendor->current_bank_account_id);
        $payout = new PayoutRequest;
        $payout->forceFill([
            'workspace_id' => $this->workspace->id,
            'number' => self::number($this->context, $seq),
            'vendor_id' => $vendor->id,
            'vendor_bank_account_id' => $account->id,
            'bank_fingerprint' => $account->fingerprint,
            'amount_minor' => $amount,
            'currency' => $currency,
            'description' => $description,
            'status' => $status,
            'risk_score' => 0,
            'risk_band' => RiskBand::Low,
            'risk_signals' => [],
            'telegram_allowed' => true,
            'requested_by' => $this->users['mark']->id,
            'submitted_at' => $this->context->at($createdOffset, '10:00'),
            'created_at' => $this->context->at($createdOffset, '09:30'),
            'updated_at' => $this->context->at($createdOffset, '09:30'),
            ...$attributes,
        ])->save();

        return $payout;
    }

    private function approvalRow(PayoutRequest $payout, string $user, ApprovalDecision $decision, int $offset, ?string $comment): void
    {
        $approval = new PayoutApproval;
        $approval->forceFill([
            'workspace_id' => $this->workspace->id,
            'payout_request_id' => $payout->id,
            'user_id' => $this->users[$user]->id,
            'decision' => $decision,
            'channel' => ApprovalChannel::Web,
            'comment' => $comment,
            'amount_minor' => $payout->amount_minor,
            'currency' => $payout->currency,
            'bank_fingerprint' => (string) $payout->bank_fingerprint,
            'risk_band' => RiskBand::Low,
            'approval_round' => 1,
            'step_up_method' => StepUpMethod::None,
            'created_at' => $this->context->at($offset, '16:00'),
        ])->save();
    }

    private function openPayouts(): void
    {
        $service = app(PayoutService::class);
        $settings = WorkspaceProvisioner::settingsFor($this->workspace);
        $create = function (int $seq, string $vendorKey, int $amount, string $currency, string $description, ?string $invoice, ?string $due, CarbonImmutable $at) use ($service, $settings): PayoutRequest {
            $this->context->travel($at);
            $settings->refresh()->forceFill(['next_payout_seq' => $seq])->save();
            $payout = $service->createDraft($this->workspace, $this->users['mark'], [
                'vendor_id' => $this->vendors[$vendorKey]->id,
                'amount_minor' => $amount,
                'currency' => $currency,
                'description' => $description,
                'vendor_invoice_number' => $invoice,
                'due_date' => $due,
            ]);

            return $service->submit($payout, $this->users['mark']);
        };
        $approve = function (PayoutRequest $payout, string $user, CarbonImmutable $at) use ($service): PayoutRequest {
            $this->context->travel($at);

            return $service->approve($payout->refresh(), $this->users[$user], DecisionContext::web(StepUpMethod::Totp, '127.0.0.1', 'Seed'), null);
        };

        $qa = $create(91, 'qacrew', 290000, 'EUR', 'Regression testing — sprint 22', 'QA-2026-22', $this->context->date(12), $this->context->at(-2, '10:15'));
        $approve($qa, 'anna', $this->context->at(-1, '11:00'));

        $pixel = $create(87, 'pixelforge', 480000, 'EUR', 'Design sprint — October (2 designers)', 'PF/2026/10/03', $this->context->date(3), $this->context->at(-1, '10:00'));
        $approve($pixel, 'dmitry', $this->context->at(-1, '18:40'));
        $this->pixelForgeChange();

        $create(90, 'cloudnest', 32000, 'USD', 'Hosting — November', null, $this->context->date(10), $this->context->at(0, '08:30'));

        $create(89, 'ivan', 720000, 'USD', 'Backend development — October + on-call', 'IS-2026-10', BusinessDate::addBusinessDays($this->context->today, 1), $this->context->at(0, '09:30'));

        $copy = $create(92, 'copywrite', 1250000, 'USD', 'Content package Q4 (40 articles)', 'CL-Q4-40', $this->context->date(20), $this->context->at(0, '09:45'));
        $approve($copy, 'anna', $this->context->at(0, '10:05'));
    }

    private function pixelForgeChange(): void
    {
        $this->context->travel($this->context->at(0, '08:12'));
        app(BankChangeService::class)->request(
            $this->vendors['pixelforge']->refresh(),
            $this->users['mark'],
            BankDetails::fromInput([
                'scheme' => 'iban',
                'account_holder_name' => 'PF Studio Payments UAB',
                'iban' => 'LT58 3250 0677 4120 0519',
                'bank_name' => 'Neris Demo Bank',
                'bank_country' => 'LT',
                'currency' => 'EUR',
            ]),
            BankChangeSource::VendorEmail,
            'billing@pixelf0rge.example',
            self::PIXELFORGE_REQUEST,
        );
    }
}
