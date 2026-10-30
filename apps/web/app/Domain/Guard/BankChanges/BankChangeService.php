<?php

declare(strict_types=1);

namespace App\Domain\Guard\BankChanges;

use App\Audit\Auditor;
use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Bank\BankDetails;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Enums\PayoutHoldReason;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\Enums\VerificationMethod;
use App\Domain\Guard\Enums\VerificationMode;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\PayoutEvaluator;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Guard\Risk\BankChangeRiskInput;
use App\Domain\Guard\Risk\BankChangeRiskScorer;
use App\Domain\Guard\Risk\RiskResult;
use App\Domain\Guard\Trusted\TrustedConfirmations;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class BankChangeService
{
    public const array STRONG_METHODS = ['callback_known_phone', 'in_person', 'signed_letter', 'video_call'];

    public const array SOLO_METHODS = ['callback_known_phone', 'in_person', 'video_call'];

    public const array CHECKLIST = ['called_known_number', 'confirmed_holder_name', 'confirmed_last4', 'confirmed_request_origin'];

    public const int SOLO_NOTE_MIN = 30;

    public const int HIGH_NOTE_MIN = 10;

    public function __construct(
        private Clock $clock,
        private Auditor $auditor,
        private GuardAlerts $alerts,
        private GuardEligibility $eligibility,
        private GuardNotifications $notifications,
        private PayoutService $payouts,
        private PayoutEvaluator $evaluator,
        private TrustedConfirmations $confirmations,
    ) {}

    public function request(
        Vendor $vendor,
        User $requester,
        BankDetails $details,
        BankChangeSource $source,
        ?string $requesterContact = null,
        ?string $requestText = null,
        ?CarbonImmutable $receivedAt = null,
    ): VendorBankAccount {
        $account = DB::transaction(function () use ($vendor, $requester, $details, $source, $requesterContact, $requestText, $receivedAt): VendorBankAccount {
            $vendor = Vendor::query()->forWorkspace($vendor->workspace_id)->lockForUpdate()->findOrFail($vendor->id);

            if ($this->evaluator->hasPendingChange($vendor)) {
                throw new GuardException('bank_change_pending');
            }

            $now = $this->clock->now();
            $version = (int) VendorBankAccount::query()->forWorkspace($vendor->workspace_id)->where('vendor_id', $vendor->id)->max('version') + 1;
            $contact = $requesterContact !== null && trim($requesterContact) !== '' ? trim($requesterContact) : null;
            $requesterEmail = $contact !== null && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($contact) : null;
            $requesterDomain = $requesterEmail !== null ? substr($requesterEmail, strrpos($requesterEmail, '@') + 1) : null;
            $text = $requestText !== null && trim($requestText) !== '' ? mb_substr(trim($requestText), 0, 10000) : null;
            $risk = $this->score($vendor, $details, $source, $requesterEmail, $requesterDomain, $text, $version, $now);

            $account = new VendorBankAccount;
            $account->forceFill([
                'workspace_id' => $vendor->workspace_id,
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
                'status' => BankAccountStatus::PendingVerification,
                'source' => $source,
                'requested_by_user_id' => $requester->id,
                'requested_at' => $receivedAt !== null && $receivedAt->lessThan($now) ? $receivedAt : $now,
                'requester_contact' => $contact,
                'requester_email_domain' => $requesterDomain,
                'change_request_text' => $text,
                'risk_score' => $risk->score,
                'risk_band' => $risk->band,
                'risk_signals' => $risk->signals,
                'verification_mode' => VerificationMode::Standard,
            ])->save();

            $this->auditor->record('payflow.bank_account.requested', $account, metadata: [
                'vendor_id' => $vendor->id,
                'version' => $version,
                'source' => $source->value,
                'last4' => $account->last4,
                'risk_score' => $risk->score,
                'risk_band' => $risk->band->value,
                'signals' => $risk->codes(),
            ], actor: $requester, workspace: $vendor->workspace_id, subjectLabel: $vendor->name);

            $this->payouts->holdVendorPayouts($vendor, PayoutHoldReason::BankDetailsChanged, $requester);

            return $account;
        });

        $this->raiseAlerts($account);

        return $account;
    }

    /** @param  array<string, mixed>  $checklist */
    public function verify(VendorBankAccount $account, User $verifier, VerificationMethod $method, array $checklist, ?string $note, bool $solo = false): VendorBankAccount
    {
        $denial = $this->verifyDenial($account, $verifier, $solo);

        if ($denial !== null) {
            throw new GuardException($denial, $denial === 'no_permission' ? 403 : 409);
        }

        foreach (self::CHECKLIST as $item) {
            if (($checklist[$item] ?? false) !== true) {
                throw new GuardException('checklist_incomplete', 422);
            }
        }

        $note = $note !== null ? trim($note) : null;

        if ($solo) {
            if (! in_array($method->value, self::SOLO_METHODS, true)) {
                throw new GuardException('method_not_allowed', 422);
            }

            if (mb_strlen((string) $note) < self::SOLO_NOTE_MIN) {
                throw new GuardException('note_required', 422, ['min' => self::SOLO_NOTE_MIN]);
            }
        } else {
            if ($account->risk_band !== RiskBand::Low && ! in_array($method->value, self::STRONG_METHODS, true)) {
                throw new GuardException('method_not_allowed', 422);
            }

            if ($account->risk_band === RiskBand::High && mb_strlen((string) $note) < self::HIGH_NOTE_MIN) {
                throw new GuardException('note_required', 422, ['min' => self::HIGH_NOTE_MIN]);
            }
        }

        $confirmation = $solo ? $this->confirmedSoloConfirmation($account) : null;

        if ($solo && $confirmation === null) {
            throw new GuardException('solo_confirmation_missing', 409);
        }

        return DB::transaction(function () use ($account, $verifier, $method, $note, $solo, $confirmation): VendorBankAccount {
            $account = VendorBankAccount::query()->forWorkspace($account->workspace_id)->lockForUpdate()->findOrFail($account->id);

            if ($account->status !== BankAccountStatus::PendingVerification) {
                throw new GuardException('not_pending');
            }

            $now = $this->clock->now();
            $settings = WorkspaceProvisioner::settingsFor($account->workspace_id);
            $hours = $solo
                ? max($settings->cooling_off_hours_high, 72)
                : match ($account->risk_band) {
                    RiskBand::Low => 0,
                    RiskBand::Medium => $settings->cooling_off_hours_medium,
                    RiskBand::High => $settings->cooling_off_hours_high,
                };

            VendorBankAccount::query()->forWorkspace($account->workspace_id)
                ->where('vendor_id', $account->vendor_id)
                ->where('status', BankAccountStatus::Verified->value)
                ->update(['status' => BankAccountStatus::Superseded->value, 'superseded_at' => $now, 'updated_at' => $now]);

            $account->forceFill([
                'status' => BankAccountStatus::Verified,
                'verification_method' => $method,
                'verification_checklist' => array_fill_keys(self::CHECKLIST, true),
                'verification_mode' => $solo ? VerificationMode::Solo : VerificationMode::Standard,
                'trusted_confirmation_id' => $confirmation?->id,
                'verified_by_user_id' => $verifier->id,
                'verified_at' => $now,
                'verification_note' => $note !== null && $note !== '' ? mb_substr($note, 0, 2000) : null,
                'cooling_off_until' => $hours > 0 ? $now->addHours($hours) : null,
            ])->save();

            Vendor::query()->forWorkspace($account->workspace_id)->whereKey($account->vendor_id)->update(['current_bank_account_id' => $account->id, 'updated_at' => $now]);

            $this->alerts->resolve($account->workspace_id, 'bca:'.$account->id, $verifier);
            $this->alerts->resolve($account->workspace_id, 'bca-high:'.$account->id, $verifier);
            $this->auditor->record($solo ? 'payflow.bank_account.verified_solo' : 'payflow.bank_account.verified', $account, metadata: [
                'vendor_id' => $account->vendor_id,
                'version' => $account->version,
                'method' => $method->value,
                'mode' => $solo ? 'solo' : 'standard',
                'trusted_confirmation_id' => $confirmation?->id,
                'cooling_off_hours' => $hours,
                'last4' => $account->last4,
            ], actor: $verifier, workspace: $account->workspace_id, subjectLabel: $account->vendor->name);
            $this->notifications->bankChangeDecided($account, $verifier, true);

            return $account;
        });
    }

    public function reject(VendorBankAccount $account, User $actor, string $reason): VendorBankAccount
    {
        $withdraw = $account->requested_by_user_id === $actor->id;
        $member = $this->eligibility->find($account->workspace_id, $actor);

        if (! $withdraw && ($member === null || ! $member->role->allows('bank_changes.verify'))) {
            throw new GuardException('no_permission', 403);
        }

        if ($account->status !== BankAccountStatus::PendingVerification) {
            throw new GuardException('not_pending');
        }

        return DB::transaction(function () use ($account, $actor, $reason, $withdraw): VendorBankAccount {
            $account->forceFill([
                'status' => BankAccountStatus::Rejected,
                'rejected_by_user_id' => $actor->id,
                'rejected_at' => $this->clock->now(),
                'rejection_reason' => mb_substr(trim($reason), 0, 500),
            ])->save();

            TrustedConfirmation::query()->forWorkspace($account->workspace_id)
                ->where('subject_type', TrustedSubjectType::VendorBankAccount->value)
                ->where('subject_id', $account->id)
                ->where('status', TrustedConfirmationStatus::Pending->value)
                ->update(['status' => TrustedConfirmationStatus::Canceled->value]);

            $this->alerts->resolve($account->workspace_id, 'bca:'.$account->id, $actor);
            $this->alerts->resolve($account->workspace_id, 'bca-high:'.$account->id, $actor);
            $this->auditor->record('payflow.bank_account.rejected', $account, metadata: [
                'vendor_id' => $account->vendor_id,
                'version' => $account->version,
                'withdrawn' => $withdraw,
                'reason' => trim($reason),
            ], actor: $actor, workspace: $account->workspace_id, subjectLabel: $account->vendor->name);

            $this->payouts->restoreAfterRejectedChange($account->vendor, $actor);
            $this->notifications->bankChangeDecided($account, $actor, false);

            return $account;
        });
    }

    public function verifyDenial(VendorBankAccount $account, User $user, bool $solo = false): ?string
    {
        if ($account->status !== BankAccountStatus::PendingVerification) {
            return 'not_pending';
        }

        $isRequester = $account->requested_by_user_id === $user->id;

        if ($isRequester && ! $solo) {
            return 'is_requester';
        }

        $member = $this->eligibility->find($account->workspace_id, $user);

        if ($member === null || ! $member->role->allows('bank_changes.verify')) {
            return 'no_permission';
        }

        if ($solo && (! $isRequester || ! $this->soloAvailable($account))) {
            return 'solo_not_available';
        }

        return $member->reason();
    }

    public function soloAvailable(VendorBankAccount $account): bool
    {
        return $this->eligibility->others($account->workspace_id, 'bank_changes.verify', $account->requested_by_user_id) === 0
            && $this->evaluator->activeTrustedContact($account->workspace_id, $this->clock->now()) !== null;
    }

    public function requestSoloConfirmation(VendorBankAccount $account, User $requester): TrustedConfirmation
    {
        if ($account->requested_by_user_id !== $requester->id) {
            throw new GuardException('no_permission', 403);
        }

        if ($account->status !== BankAccountStatus::PendingVerification) {
            throw new GuardException('not_pending');
        }

        $contact = $this->evaluator->activeTrustedContact($account->workspace_id, $this->clock->now());

        if ($contact === null || ! $this->soloAvailable($account)) {
            throw new GuardException('solo_not_available');
        }

        return $this->confirmations->create($contact, TrustedSubjectType::VendorBankAccount, $account->id, [
            'vendor' => $account->vendor->name,
            'last4' => $account->last4,
            'bank_country' => $account->bank_country,
            'currency' => $account->currency,
            'fingerprint8' => $account->fingerprint8(),
            'risk_band' => $account->risk_band->value,
            'risk_score' => $account->risk_score,
            'signals' => implode(',', array_map(fn (array $signal): string => $signal['code'], $account->risk_signals)),
            'requester' => UserRef::name($account->requester),
        ], $requester);
    }

    public function latestSoloConfirmation(VendorBankAccount $account): ?TrustedConfirmation
    {
        return TrustedConfirmation::query()->forWorkspace($account->workspace_id)
            ->with('contact')
            ->where('subject_type', TrustedSubjectType::VendorBankAccount->value)
            ->where('subject_id', $account->id)
            ->latest('id')
            ->first();
    }

    public function markViewed(VendorBankAccount $account, User $viewer): bool
    {
        $key = 'pf-bank-viewed:'.$viewer->id.':'.$account->id;

        if (! Cache::add($key, 1, 600)) {
            return false;
        }

        $this->auditor->record('payflow.bank_account.viewed', $account, metadata: ['vendor_id' => $account->vendor_id, 'version' => $account->version], actor: $viewer, workspace: $account->workspace_id, subjectLabel: $account->vendor->name);

        return true;
    }

    /** @return array{iban: string|null, account_number: string|null, routing_number: string|null, swift_bic: string|null, expires_in: int} */
    public function reveal(VendorBankAccount $account, User $viewer): array
    {
        $this->auditor->record('payflow.bank_account.revealed', $account, metadata: [
            'vendor_id' => $account->vendor_id,
            'version' => $account->version,
            'last4' => $account->last4,
        ], actor: $viewer, workspace: $account->workspace_id, subjectLabel: $account->vendor->name);

        return [
            'iban' => $account->iban,
            'account_number' => $account->account_number,
            'routing_number' => $account->routing_number,
            'swift_bic' => $account->swift_bic,
            'expires_in' => 60,
        ];
    }

    public function score(Vendor $vendor, BankDetails $details, BankChangeSource $source, ?string $requesterEmail, ?string $requesterDomain, ?string $text, int $version, CarbonImmutable $now): RiskResult
    {
        $previous = $this->evaluator->currentAccount($vendor);
        $fingerprint = $details->fingerprint();
        $workspace = Workspace::query()->findOrFail($vendor->workspace_id);
        $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();

        $shared = VendorBankAccount::query()->forWorkspace($vendor->workspace_id)
            ->where('fingerprint', $fingerprint)
            ->where('vendor_id', '!=', $vendor->id)
            ->where('status', '!=', BankAccountStatus::Rejected->value)
            ->distinct()
            ->count('vendor_id');
        $reused = VendorBankAccount::query()->forWorkspace($vendor->workspace_id)
            ->where('fingerprint', $fingerprint)
            ->where('status', BankAccountStatus::Rejected->value)
            ->exists();
        $pending = PayoutRequest::query()->forWorkspace($vendor->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->where(fn ($query) => $query->whereIn('status', [PayoutStatus::PendingApproval->value, PayoutStatus::Approved->value])
                ->orWhere(fn ($inner) => $inner->whereIn('status', [PayoutStatus::Draft->value, PayoutStatus::OnHold->value])
                    ->whereNotNull('due_date')
                    ->where('due_date', '<=', CarbonImmutable::parse($today)->addDays(14)->toDateString())))
            ->count();
        $recent = VendorBankAccount::query()->forWorkspace($vendor->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->where('requested_at', '>=', $now->subDays(90))
            ->count();

        return BankChangeRiskScorer::score(new BankChangeRiskInput(
            source: $source,
            holderName: $details->holderName,
            vendorName: $vendor->name,
            vendorLegalName: $vendor->legal_name,
            vendorEmail: $vendor->email,
            requesterEmail: $requesterEmail,
            requesterDomain: $source === BankChangeSource::VendorEmail ? $requesterDomain : null,
            bankCountry: $details->bankCountry,
            currency: $details->currency,
            previousCountry: $previous !== null ? $previous->bank_country : $vendor->country,
            previousCurrency: $previous?->currency,
            sharedWithOtherVendors: $shared,
            reusesRejectedAccount: $reused,
            pendingPayouts: $pending,
            recentChanges: $recent,
            requestText: $text,
            vendorPhone: $vendor->phone,
            vendorAgeDays: $vendor->created_at !== null ? (int) floor($vendor->created_at->diffInDays($now)) : null,
            version: $version,
            phoneChangedDaysAgo: $vendor->phone_changed_at !== null ? (int) floor($vendor->phone_changed_at->diffInDays($now)) : null,
        ));
    }

    private function confirmedSoloConfirmation(VendorBankAccount $account): ?TrustedConfirmation
    {
        return TrustedConfirmation::query()->forWorkspace($account->workspace_id)
            ->where('subject_type', TrustedSubjectType::VendorBankAccount->value)
            ->where('subject_id', $account->id)
            ->where('status', TrustedConfirmationStatus::Confirmed->value)
            ->latest('id')
            ->first();
    }

    private function raiseAlerts(VendorBankAccount $account): void
    {
        $vendor = $account->vendor;
        $data = ['vendor' => $vendor->name, 'last4' => $account->last4, 'score' => $account->risk_score, 'band' => $account->risk_band->value];
        $alert = $this->alerts->ensure($account->workspace_id, AlertType::BankChangeRequested, AlertSubjectType::VendorBankAccount, $account->id, 'bca:'.$account->id, $data);

        if ($account->risk_band === RiskBand::High) {
            $alert = $this->alerts->ensure($account->workspace_id, AlertType::BankChangeHighRisk, AlertSubjectType::VendorBankAccount, $account->id, 'bca-high:'.$account->id, $data);
        }

        foreach ($account->risk_signals as $signal) {
            if ($signal['code'] === 'shared_account') {
                $this->alerts->ensure($account->workspace_id, AlertType::SharedBankAccount, AlertSubjectType::VendorBankAccount, $account->id, 'shared:'.$account->fingerprint8(), $data);
            }
        }

        $this->notifications->bankChangeRequested($account, $alert);
    }
}
