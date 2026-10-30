<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Bank\BankRules;
use App\Domain\Guard\BankChanges\BankChangeService;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutHoldReason;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\VerificationMethod;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\StepUp\StepUpGate;
use App\Domain\Guard\Trusted\TrustedContactService;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Time\Clock;
use App\Http\Requests\Payflow\VerifyBankChangeRequest;
use App\Http\Resources\Payflow\GuardResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BankChangesController extends PayflowController
{
    public function show(Request $request, VendorBankAccount $account): Response
    {
        $this->authorize('view', $account);
        $user = $this->user($request);
        $role = $this->role();
        $account->load(['requester', 'verifier', 'rejecter']);
        $vendor = Vendor::withTrashed()->forWorkspace($account->workspace_id)->findOrFail($account->vendor_id);
        $previous = VendorBankAccount::query()->forWorkspace($account->workspace_id)
            ->with(['requester', 'verifier', 'rejecter'])
            ->where('vendor_id', $account->vendor_id)
            ->where('version', '<', $account->version)
            ->whereIn('status', [BankAccountStatus::Verified->value, BankAccountStatus::Superseded->value])
            ->orderByDesc('version')
            ->first();
        $this->service()->markViewed($account, $user);

        $settings = WorkspaceProvisioner::settingsFor($this->workspace());
        $now = app(Clock::class)->now();
        $soloAvailable = $account->status === BankAccountStatus::PendingVerification && $this->service()->soloAvailable($account);
        $confirmation = $this->service()->latestSoloConfirmation($account);
        $denial = $this->service()->verifyDenial($account, $user);
        $soloDenial = $this->service()->verifyDenial($account, $user, true);
        $high = $account->risk_band === RiskBand::High;

        return Inertia::render('guard/BankChangeReview', [
            'change' => [
                ...GuardResource::bankAccount($account),
                'change_request_text' => $account->change_request_text,
                'requester_contact_hint' => self::contactHint($account),
            ],
            'previous' => $previous !== null ? GuardResource::bankAccount($previous) : null,
            'diff' => self::diff($previous, $account),
            'vendor' => GuardResource::vendorDetail($vendor),
            'affected_payouts' => PayoutRequest::query()->forWorkspace($account->workspace_id)
                ->with(['vendor', 'requester'])
                ->where('vendor_id', $account->vendor_id)
                ->where(fn ($query) => $query->whereIn('status', [PayoutStatus::PendingApproval->value, PayoutStatus::Approved->value])
                    ->orWhere(fn ($inner) => $inner->where('status', PayoutStatus::OnHold->value)->where('hold_reason', PayoutHoldReason::BankDetailsChanged->value)))
                ->orderBy('id')
                ->get()
                ->map(fn (PayoutRequest $payout): array => GuardResource::payoutListItem($payout, $user, $role))
                ->all(),
            'requirements' => [
                'allowed_methods' => self::allowedMethods($account->risk_band),
                'note_required' => $high,
                'note_min' => $high ? BankChangeService::HIGH_NOTE_MIN : 0,
                'step_up_required' => $account->risk_band !== RiskBand::Low,
                'cooling_off_hours' => match ($account->risk_band) {
                    RiskBand::Low => 0,
                    RiskBand::Medium => $settings->cooling_off_hours_medium,
                    RiskBand::High => $settings->cooling_off_hours_high,
                },
                'solo' => [
                    'available' => $soloAvailable,
                    'trusted_contact' => GuardResource::trustedContact(TrustedContactService::current($this->workspace())),
                    'confirmation' => GuardResource::confirmationInfo($confirmation),
                ],
            ],
            'callback' => [
                'known_phone' => $vendor->phone,
                'phone_verified_at' => Iso::dateTime($vendor->phone_verified_at),
                'phone_recently_changed' => $vendor->phone_changed_at !== null && $vendor->phone_changed_at->greaterThan($now->subDays(30)),
            ],
            'can' => $this->pageCan([
                'verify' => $denial === null,
                'verify_solo' => $soloDenial === null && $confirmation !== null && $confirmation->status->value === 'confirmed',
                'reject' => $account->status === BankAccountStatus::PendingVerification && $user->can('reject', $account) && $account->requested_by_user_id !== $user->id,
                'withdraw' => $account->status === BankAccountStatus::PendingVerification && $account->requested_by_user_id === $user->id,
                'reveal' => $role->allows('bank_details.reveal'),
            ]),
            'cannot_verify_reason' => self::cannotVerify($denial),
            'disclaimer_url' => route('legal', ['doc' => 'payment-guard-disclaimer']),
        ]);
    }

    public function verify(VerifyBankChangeRequest $request, VendorBankAccount $account): RedirectResponse
    {
        $this->authorize('verify', $account);
        $solo = $request->boolean('solo');
        $denial = $this->service()->verifyDenial($account, $this->user($request), $solo);

        if ($denial !== null) {
            throw new GuardException($denial, $denial === 'no_permission' ? 403 : 409);
        }

        if ($solo || $account->risk_band !== RiskBand::Low) {
            StepUpGate::require($request);
        } else {
            StepUpGate::assertSecondFactor($request);
        }

        $this->service()->verify(
            $account,
            $this->user($request),
            VerificationMethod::from((string) $request->validated('method')),
            $request->checklist(),
            $request->filled('note') ? (string) $request->input('note') : null,
            $solo,
        );

        return $this->to('guard.bank-changes.show', $account->id, $solo ? 'bank_change_verified_solo' : 'bank_change_verified');
    }

    public function reject(Request $request, VendorBankAccount $account): RedirectResponse
    {
        $this->authorize('reject', $account);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $user = $this->user($request);

        if ($account->requested_by_user_id !== $user->id && ($account->risk_band !== RiskBand::Low)) {
            StepUpGate::require($request);
        }

        $this->service()->reject($account, $user, (string) $data['reason']);

        return $this->to('guard.bank-changes.show', $account->id, $account->requested_by_user_id === $user->id ? 'bank_change_withdrawn' : 'bank_change_rejected');
    }

    public function soloConfirmation(Request $request, VendorBankAccount $account): RedirectResponse
    {
        $this->authorize('view', $account);
        abort_unless($this->role()->allows('bank_changes.request'), 403);
        $this->service()->requestSoloConfirmation($account, $this->user($request));

        return $this->to('guard.bank-changes.show', $account->id, 'solo_confirmation_sent');
    }

    /** @return list<string> */
    private static function allowedMethods(RiskBand $band): array
    {
        $methods = [];

        foreach (VerificationMethod::cases() as $method) {
            if ($band === RiskBand::Low || in_array($method->value, BankChangeService::STRONG_METHODS, true)) {
                $methods[] = $method->value;
            }
        }

        return $methods;
    }

    private static function cannotVerify(?string $denial): ?string
    {
        return match ($denial) {
            null => null,
            'is_requester', 'no_permission', 'not_pending', 'not_eligible_yet', 'second_factor_missing' => $denial,
            default => 'no_permission',
        };
    }

    private static function contactHint(VendorBankAccount $account): ?string
    {
        if ($account->requester_email_domain !== null) {
            return $account->requester_email_domain;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $account->requester_contact);

        return $digits !== '' ? '•••'.substr($digits, -3) : null;
    }

    /** @return list<array{field: string, before: string|null, after: string|null, changed: bool}> */
    private static function diff(?VendorBankAccount $before, VendorBankAccount $after): array
    {
        $values = fn (?VendorBankAccount $account): array => $account === null ? [] : [
            'scheme' => $account->scheme->value,
            'holder_name' => $account->account_holder_name,
            'account' => GuardResource::display($account),
            'bank_name' => $account->bank_name,
            'bank_country' => $account->bank_country,
            'currency' => $account->currency,
            'swift_bic' => BankRules::maskSwift($account->swift_bic),
        ];
        $old = $values($before);
        $new = $values($after);
        $rows = [];

        foreach (['scheme', 'holder_name', 'account', 'bank_name', 'bank_country', 'currency', 'swift_bic'] as $field) {
            $rows[] = [
                'field' => $field,
                'before' => $old[$field] ?? null,
                'after' => $new[$field] ?? null,
                'changed' => ($old[$field] ?? null) !== ($new[$field] ?? null),
            ];
        }

        return $rows;
    }

    private function service(): BankChangeService
    {
        return app(BankChangeService::class);
    }
}
