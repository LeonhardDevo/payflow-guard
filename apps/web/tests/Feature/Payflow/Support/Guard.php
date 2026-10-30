<?php

declare(strict_types=1);

namespace Tests\Feature\Payflow\Support;

use App\Domain\Guard\Bank\BankDetails;
use App\Domain\Guard\BankChanges\BankChangeService;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Enums\VerificationMethod;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Guard\Vendors\VendorService;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Enums\WorkspaceRole;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

final class Guard
{
    public const array THRESHOLDS = [
        'USD' => ['auto_approve_below_minor' => 50000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => 100000],
        'EUR' => ['auto_approve_below_minor' => 50000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => 100000],
    ];

    /** @param  array<string, mixed>  $settings */
    public static function workspace(Workspace $workspace, array $settings = []): Workspace
    {
        Payflow::pro($workspace);
        WorkspaceProvisioner::settingsFor($workspace)->forceFill([
            'approval_thresholds' => self::THRESHOLDS,
            'require_second_factor' => false,
            'guard_trust_delay_hours' => 72,
            'allow_telegram_approvals' => true,
            ...$settings,
        ])->save();

        return $workspace;
    }

    public static function member(Workspace $workspace, WorkspaceRole $role, int $eligibleHoursAgo = 200, bool $twoFactor = true, ?string $email = null): User
    {
        $user = User::factory()->create(array_filter([
            'email' => $email,
            'two_factor_secret' => $twoFactor ? encrypt('JBSWY3DPEHPK3PXP') : null,
            'two_factor_confirmed_at' => $twoFactor ? now() : null,
        ], fn (mixed $value): bool => $value !== null));
        $workspace->addMember($user, $role);
        $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        self::eligible($workspace, $user, $eligibleHoursAgo);

        return $user->refresh();
    }

    public static function eligible(Workspace $workspace, User $user, int $hoursAgo = 200): void
    {
        DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $user->id)
            ->update(['guard_eligible_from' => app(Clock::class)->now()->subHours($hoursAgo)]);
    }

    public static function telegram(User $user, Workspace $workspace, int $telegramId): TelegramAccount
    {
        return TelegramAccount::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'telegram_user_id' => $telegramId,
            'chat_id' => $telegramId,
            'linked_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function vendor(Workspace $workspace, User $actor, array $attributes = []): Vendor
    {
        return app(CurrentWorkspace::class)->runAs($workspace, fn (): Vendor => app(VendorService::class)->create($workspace, $actor, [
            'name' => 'Vendor '.fake()->unique()->numerify('####'),
            'legal_name' => null,
            'category' => 'contractor',
            'email' => 'billing@vendor.example',
            'phone' => '+48 22 555 01 47',
            'country' => 'LT',
            'default_currency' => 'EUR',
            ...$attributes,
        ]));
    }

    /** @return array<string, string> */
    public static function iban(string $holder, string $iban = 'LT583250067741200519', string $currency = 'EUR'): array
    {
        return ['scheme' => 'iban', 'account_holder_name' => $holder, 'iban' => $iban, 'bank_country' => substr($iban, 0, 2), 'currency' => $currency, 'bank_name' => 'Neris Demo Bank'];
    }

    /** @param  array<string, string>|null  $details */
    public static function verifiedAccount(Vendor $vendor, User $requester, User $verifier, ?array $details = null, BankChangeSource $source = BankChangeSource::Onboarding): VendorBankAccount
    {
        $workspace = Workspace::query()->findOrFail($vendor->workspace_id);

        return app(CurrentWorkspace::class)->runAs($workspace, function () use ($vendor, $requester, $verifier, $details, $source): VendorBankAccount {
            $service = app(BankChangeService::class);
            $account = $service->request($vendor, $requester, BankDetails::fromInput($details ?? self::iban($vendor->legal_name ?? $vendor->name)), $source);

            return $service->verify($account, $verifier, VerificationMethod::CallbackKnownPhone, array_fill_keys(BankChangeService::CHECKLIST, true), 'Called the known number and confirmed every detail.');
        });
    }

    /** @param  array<string, string>  $input */
    public static function existingAccount(Vendor $vendor, User $requester, array $input): VendorBankAccount
    {
        $details = BankDetails::fromInput($input);
        $account = new VendorBankAccount;
        $account->forceFill([
            'workspace_id' => $vendor->workspace_id,
            'vendor_id' => $vendor->id,
            'version' => 1,
            'scheme' => $details->scheme,
            'account_holder_name' => $details->holderName,
            'iban' => $details->iban,
            'account_number' => $details->accountNumber,
            'routing_number' => $details->routingNumber,
            'bank_country' => $details->bankCountry,
            'currency' => $details->currency,
            'last4' => $details->last4(),
            'fingerprint' => $details->fingerprint(),
            'status' => 'verified',
            'source' => 'import',
            'requested_by_user_id' => $requester->id,
            'requested_at' => app(Clock::class)->now()->subDays(400),
            'verified_at' => app(Clock::class)->now()->subDays(400),
            'risk_score' => 0,
            'risk_band' => 'low',
            'risk_signals' => [],
        ])->save();
        $vendor->forceFill(['current_bank_account_id' => $account->id])->save();

        return $account;
    }

    public static function backdateVerification(VendorBankAccount $account, int $days): void
    {
        $account->forceFill([
            'verified_at' => app(Clock::class)->now()->subDays($days),
            'requested_at' => app(Clock::class)->now()->subDays($days + 1),
            'cooling_off_until' => null,
        ])->save();
    }

    public static function payout(Workspace $workspace, User $requester, Vendor $vendor, int $amountMinor, string $currency = 'EUR', bool $submit = true, ?string $invoice = null): PayoutRequest
    {
        return app(CurrentWorkspace::class)->runAs($workspace, function () use ($workspace, $requester, $vendor, $amountMinor, $currency, $submit, $invoice): PayoutRequest {
            $service = app(PayoutService::class);
            $payout = $service->createDraft($workspace, $requester, [
                'vendor_id' => $vendor->id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'description' => 'Services rendered',
                'vendor_invoice_number' => $invoice,
                'due_date' => null,
            ]);

            return $submit ? $service->submit($payout, $requester) : $payout;
        });
    }

    /** @return array<string, int|string> */
    public static function stepUp(string $method = 'totp'): array
    {
        return ['payflow.stepup_at' => app(Clock::class)->now()->getTimestamp(), 'payflow.stepup_method' => $method];
    }
}
