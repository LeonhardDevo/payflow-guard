<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Audit\Auditor;
use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Analytics\GuardSummary;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Eligibility\EligibleMember;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\AlertSeverity;
use App\Domain\Guard\Enums\AlertStatus;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\PayoutEvaluator;
use App\Domain\Guard\Trusted\TrustedContactService;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Http\Resources\Payflow\GuardResource;
use App\Http\Resources\Payflow\Paginated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class GuardController extends PayflowController
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $user = $this->user($request);
        $role = $this->role();
        abort_unless($role->allows('vendors.view') || $role->allows('payouts.view'), 403);
        $filters = $request->validate([
            'severity' => ['nullable', Rule::enum(AlertSeverity::class)],
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved', 'active'])],
        ]);
        $status = (string) ($filters['status'] ?? 'active');
        $now = app(Clock::class)->now();
        $monthStart = WorkspaceClock::todayIn($workspace->timezone, $now)->startOfMonth()->utc();
        $settings = WorkspaceProvisioner::settingsFor($workspace);

        $pending = PayoutRequest::query()->forWorkspace($workspace)->with(['vendor', 'requester'])
            ->where('status', PayoutStatus::PendingApproval->value)->orderBy('submitted_at')->get();
        $items = $pending->map(fn (PayoutRequest $payout): array => GuardResource::payoutListItem($payout, $user, $role));
        $needs = $items->sortByDesc(fn (array $item): int => $item['awaiting_me'] ? 1 : 0)->take(10)->values()->all();

        $changes = VendorBankAccount::query()->forWorkspace($workspace)
            ->with(['vendor', 'requester', 'verifier', 'rejecter'])
            ->where('status', BankAccountStatus::PendingVerification->value)
            ->orderByRaw("case risk_band when 'high' then 0 when 'medium' then 1 else 2 end")
            ->orderBy('requested_at')
            ->get();

        $alerts = GuardAlert::query()->forWorkspace($workspace)->with('acknowledger')
            ->when($status === 'active', fn ($query) => $query->where('status', '!=', AlertStatus::Resolved->value))
            ->when($status !== 'active', fn ($query) => $query->where('status', $status))
            ->when($filters['severity'] ?? null, fn ($query, string $severity) => $query->where('severity', $severity))
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $members = $this->eligibility()->members($workspace, 'payouts.approve');
        $eligible = array_filter($members, fn (EligibleMember $member): bool => $member->eligible());
        $waiting = array_values(array_filter($members, fn (EligibleMember $member): bool => ! $member->trustDelayPassed && $member->eligibleFrom !== null));
        $me = $this->eligibility()->find($workspace, $user);
        $contact = TrustedContactService::current($workspace);

        return Inertia::render('guard/Index', [
            'kpis' => [
                'awaiting_me' => $items->where('awaiting_me', true)->count(),
                'pending_approvals' => ['count' => $pending->count(), 'amounts' => self::sums($pending->groupBy('currency')->map(fn ($group): int => (int) $group->sum('amount_minor'))->all())],
                'bank_changes_pending' => $changes->count(),
                'bank_changes_high' => $changes->where('risk_band', RiskBand::High)->count(),
                'on_hold' => PayoutRequest::query()->forWorkspace($workspace)->where('status', PayoutStatus::OnHold->value)->count(),
                'released_this_month' => self::sums(PayoutRequest::query()->forWorkspace($workspace)
                    ->where('status', PayoutStatus::Released->value)
                    ->where('released_at', '>=', $monthStart)
                    ->selectRaw('currency, sum(amount_minor) as total')
                    ->groupBy('currency')
                    ->pluck('total', 'currency')
                    ->map(fn (mixed $total): int => (int) $total)
                    ->all()),
            ],
            'needs_decision' => $needs,
            'bank_changes' => $changes->map(fn (VendorBankAccount $account): array => [
                'account' => GuardResource::bankAccount($account),
                'vendor' => GuardResource::vendorRef($account->vendor),
            ])->all(),
            'alerts' => Paginated::of($alerts, $alerts->getCollection()->map(fn (GuardAlert $alert): array => GuardSummary::alertItem($alert))->all()),
            'alert_filters' => ['severity' => $filters['severity'] ?? null, 'status' => $status],
            'rules' => [
                ...GuardResource::guardSettings($settings),
                'eligible_approvers' => count($eligible),
                'pending_eligibility' => array_map(fn (EligibleMember $member): array => [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                    'eligible_from' => Iso::dateTime($member->eligibleFrom),
                ], $waiting),
                'trusted_contact' => GuardResource::trustedContact($contact),
                'solo_mode' => count($eligible) <= 1 && $this->evaluator()->activeTrustedContact($workspace->id, $now) !== null,
            ],
            'me' => [
                'privileged' => $role->isGuardPrivileged(),
                'eligible_from' => Iso::dateTime($me?->eligibleFrom),
                'missing_second_factor' => $me !== null && $me->missingSecondFactor,
            ],
        ]);
    }

    public function acknowledge(Request $request, GuardAlert $alert, GuardAlerts $alerts, Auditor $auditor): RedirectResponse
    {
        $this->authorize('handle', $alert);
        $user = $this->user($request);
        $alerts->acknowledge($alert, $user);
        $auditor->record('payflow.alert.acknowledged', $alert, metadata: ['type' => $alert->type->value], actor: $user, workspace: $alert->workspace_id, subjectLabel: $alert->type->value);

        return $this->back('alert_acknowledged');
    }

    public function resolve(Request $request, GuardAlert $alert, GuardAlerts $alerts, Auditor $auditor): RedirectResponse
    {
        $this->authorize('handle', $alert);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $user = $this->user($request);
        $alerts->resolve($alert->workspace_id, $alert->dedupe_key, $user, isset($data['note']) ? (string) $data['note'] : null);
        $auditor->record('payflow.alert.resolved', $alert, metadata: ['type' => $alert->type->value], actor: $user, workspace: $alert->workspace_id, subjectLabel: $alert->type->value);

        return $this->back('alert_resolved');
    }

    /**
     * @param  array<string, int>  $byCurrency
     * @return list<array{minor: int, currency: string}>
     */
    private static function sums(array $byCurrency): array
    {
        ksort($byCurrency);
        $result = [];

        foreach ($byCurrency as $currency => $minor) {
            $result[] = MoneyData::of($minor, (string) $currency);
        }

        return $result;
    }

    private function eligibility(): GuardEligibility
    {
        return app(GuardEligibility::class);
    }

    private function evaluator(): PayoutEvaluator
    {
        return app(PayoutEvaluator::class);
    }
}
