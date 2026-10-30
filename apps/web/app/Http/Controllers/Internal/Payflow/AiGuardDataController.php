<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutHoldReason;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Guard\Risk\BankChangeRiskScorer;
use App\Domain\Guard\Text\NameNormalizer;
use App\Domain\Guard\Text\TrigramSimilarity;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiGuardDataController extends AiDataController
{
    public function bankChanges(Request $request): JsonResponse
    {
        return $this->respond($request, 'vendors.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request): array|JsonResponse {
            $days = max(1, min(365, (int) ($request->query('days') ?? 90)));
            $status = (string) ($request->query('status') ?? 'any');
            $vendorQuery = trim((string) $request->query('vendor_query', ''));
            $vendor = null;
            $resolved = null;

            if ($vendorQuery !== '') {
                $match = $this->resolveVendor($workspace, $vendorQuery, $locale);

                if ($match instanceof JsonResponse) {
                    return $match;
                }

                [$vendor, $score] = $match;
                $resolved = ['kind' => 'vendor', 'id' => $vendor->id, 'name' => $vendor->name, 'score' => $score];
            }

            $timezone = WorkspaceClock::zone($workspace->timezone);
            $accounts = VendorBankAccount::query()->forWorkspace($workspace)
                ->with(['vendor', 'requester', 'verifier'])
                ->where('requested_at', '>=', $now->subDays($days))
                ->when($vendor !== null, fn ($query) => $query->where('vendor_id', $vendor?->id))
                ->when(in_array($status, ['pending_verification', 'verified', 'rejected'], true), fn ($query) => $query->where('status', $status))
                ->where('version', '>', 1)
                ->orderByDesc('requested_at')
                ->limit(10)
                ->get();

            $items = $accounts->map(function (VendorBankAccount $account) use ($workspace, $locale, $timezone): array {
                $previous = VendorBankAccount::query()->forWorkspace($workspace)
                    ->where('vendor_id', $account->vendor_id)
                    ->where('version', '<', $account->version)
                    ->whereIn('status', [BankAccountStatus::Verified->value, BankAccountStatus::Superseded->value])
                    ->orderByDesc('version')
                    ->first();
                $signals = $account->risk_signals;
                usort($signals, fn (array $a, array $b): int => $b['points'] <=> $a['points']);

                return [
                    'vendor' => $account->vendor->name,
                    'version' => $account->version,
                    'status' => $account->status->value,
                    'requested_at' => self::instant($account->requested_at, $timezone, $locale),
                    'requested_by' => UserRef::name($account->requester),
                    'source' => $account->source->value,
                    'risk_score' => $account->risk_score,
                    'risk_band' => $account->risk_band->value,
                    'top_signals' => array_slice(array_map(fn (array $signal): string => $signal['code'], $signals), 0, 3),
                    'before' => $previous !== null ? self::side($previous) : null,
                    'after' => self::side($account),
                    'verified_by' => $account->verifier !== null ? UserRef::name($account->verifier) : null,
                    'verified_at' => self::instant($account->verified_at, $timezone, $locale),
                    'verification_mode' => $account->verification_mode->value,
                    'cooling_off_until' => self::instant($account->cooling_off_until, $timezone, $locale),
                    'affected_payouts' => PayoutRequest::query()->forWorkspace($workspace)
                        ->where('vendor_id', $account->vendor_id)
                        ->where(fn ($query) => $query->whereIn('status', [PayoutStatus::PendingApproval->value, PayoutStatus::Approved->value])
                            ->orWhere(fn ($inner) => $inner->where('status', PayoutStatus::OnHold->value)->where('hold_reason', PayoutHoldReason::BankDetailsChanged->value)))
                        ->orderBy('number')
                        ->pluck('number')
                        ->all(),
                ];
            })->values()->all();

            $summary = $items === []
                ? (string) __('payflow.ai.bank_changes_none', ['days' => $days])
                : implode(' ', array_map(fn (array $item): string => (string) __('payflow.ai.bank_change_line', [
                    'vendor' => $item['vendor'],
                    'status' => (string) __('payflow.bank_statuses.'.$item['status']),
                    'band' => (string) __('payflow.risk_bands.'.$item['risk_band']),
                    'score' => $item['risk_score'],
                    'last4' => $item['after']['last4'],
                ]), $items));

            return ['data' => ['items' => $items], 'summary' => $summary, ...($resolved !== null ? ['resolved' => $resolved] : [])];
        });
    }

    public function pendingPayouts(Request $request, GuardEligibility $eligibility, PayoutService $payouts): JsonResponse
    {
        return $this->respond($request, 'payouts.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request, $eligibility, $payouts): array {
            $user = User::query()->findOrFail((int) $request->query('user_id'));
            $member = $eligibility->find($workspace, $user);
            $timezone = WorkspaceClock::zone($workspace->timezone);
            $items = PayoutRequest::query()->forWorkspace($workspace)
                ->with('vendor')
                ->where('status', PayoutStatus::PendingApproval->value)
                ->orderBy('submitted_at')
                ->limit(20)
                ->get()
                ->map(fn (PayoutRequest $payout): array => [
                    'number' => $payout->number,
                    'vendor' => $payout->vendor->name,
                    'amount' => self::money($payout->amount_minor, $payout->currency, $locale),
                    'risk_band' => $payout->risk_band?->value,
                    'required_approvals' => $payout->required_approvals,
                    'approvals_count' => $payout->approvals_count,
                    'awaiting_user' => $payouts->approvalDenial($payout, $user) === null,
                    'eligible_from' => $member !== null && ! $member->trustDelayPassed && $member->eligibleFrom !== null
                        ? self::instant($member->eligibleFrom, $timezone, $locale)['formatted'] ?? null
                        : null,
                    'submitted_at' => self::instant($payout->submitted_at, $timezone, $locale),
                    'url' => route('payouts.show', $payout->id),
                ])
                ->values()
                ->all();

            return [
                'data' => ['items' => $items],
                'summary' => (string) trans_choice('payflow.ai.payouts_pending', count($items), ['count' => count($items)]),
            ];
        });
    }

    /** @return array{bank_country: string, currency: string, last4: string, holder_matches_vendor: bool, holder_similarity: string} */
    private static function side(VendorBankAccount $account): array
    {
        $similarity = BankChangeRiskScorer::holderSimilarity($account->account_holder_name, $account->vendor->name, $account->vendor->legal_name);

        return [
            'bank_country' => $account->bank_country,
            'currency' => $account->currency,
            'last4' => $account->last4,
            'holder_matches_vendor' => $similarity >= BankChangeRiskScorer::HOLDER_MATCH_THRESHOLD,
            'holder_similarity' => BankChangeRiskScorer::similarityBucket($similarity),
        ];
    }

    /** @return array{0: Vendor, 1: float}|JsonResponse */
    private function resolveVendor(Workspace $workspace, string $query, string $locale): array|JsonResponse
    {
        $exact = Vendor::query()->forWorkspace($workspace)
            ->where(fn ($inner) => $inner->whereRaw('lower(name) = ?', [mb_strtolower($query)])->orWhereRaw('lower(coalesce(legal_name, \'\')) = ?', [mb_strtolower($query)]))
            ->first();

        if ($exact !== null) {
            return [$exact, 1.0];
        }

        $normalized = NameNormalizer::normalize($query);
        $scored = Vendor::query()->forWorkspace($workspace)->get()
            ->map(fn (Vendor $vendor): array => ['vendor' => $vendor, 'score' => max(
                TrigramSimilarity::raw($normalized, NameNormalizer::normalize($vendor->name)),
                $vendor->legal_name !== null ? TrigramSimilarity::raw($normalized, NameNormalizer::normalize($vendor->legal_name)) : 0.0,
            )])
            ->filter(fn (array $row): bool => $row['score'] >= 0.3)
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            return response()->json(['error' => 'not_found', 'summary' => (string) trans('payflow.ai.not_found', [], $locale)]);
        }

        if ($scored->count() > 1 && $scored[0]['score'] - $scored[1]['score'] < 0.05) {
            $candidates = $scored->take(5)->map(fn (array $row): array => ['id' => $row['vendor']->id, 'name' => $row['vendor']->name])->values()->all();

            return response()->json([
                'error' => 'ambiguous',
                'summary' => (string) trans('payflow.ai.ambiguous', ['names' => implode(', ', array_column($candidates, 'name'))], $locale),
                'candidates' => $candidates,
            ]);
        }

        return [$scored[0]['vendor'], round((float) $scored[0]['score'], 2)];
    }
}
