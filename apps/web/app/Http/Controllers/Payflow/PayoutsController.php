<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Guard\Eligibility\EligibleMember;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\StepUpMethod;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\Enums\VendorStatus;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\DecisionContext;
use App\Domain\Guard\Payouts\PayoutEvaluator;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Guard\StepUp\StepUpGate;
use App\Domain\Guard\Trusted\TrustedConfirmations;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\Clock;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Payflow\PayoutFormRequest;
use App\Http\Resources\Payflow\GuardResource;
use App\Http\Resources\Payflow\Paginated;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PayoutsController extends PayflowController
{
    public const array TABS = ['awaiting_me', 'pending', 'approved', 'on_hold', 'released', 'all'];

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $user = $this->user($request);
        $role = $this->role();
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'vendor_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $tab = (string) ($filters['tab'] ?? ($role->allows('payouts.approve') ? 'awaiting_me' : 'pending'));
        $vendorId = isset($filters['vendor_id']) ? (int) $filters['vendor_id'] : null;
        $search = isset($filters['q']) ? (string) $filters['q'] : null;
        $paginator = $this->tabQuery($workspace, $user, $role, $tab, $vendorId, $search)
            ->with(['vendor', 'requester'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
        $counts = [];

        foreach (self::TABS as $name) {
            $counts[$name] = $this->tabQuery($workspace, $user, $role, $name, $vendorId, $search)->count();
        }

        return Inertia::render('payouts/Index', [
            'tab' => $tab,
            'payouts' => Paginated::of($paginator, $paginator->getCollection()->map(fn (PayoutRequest $payout): array => GuardResource::payoutListItem($payout, $user, $role))->all()),
            'counts' => $counts,
            'filters' => ['vendor_id' => isset($filters['vendor_id']) ? (int) $filters['vendor_id'] : null, 'q' => $filters['q'] ?? null],
            'vendors' => Vendor::query()->forWorkspace($workspace)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Vendor $vendor): array => ['value' => $vendor->id, 'label' => $vendor->name])->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('payouts/Create', $this->formProps(null, $request));
    }

    public function store(PayoutFormRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $payout = $this->payouts()->createDraft($this->workspace(), $user, $request->payload());

        if ($request->boolean('submit')) {
            $this->payouts()->submit($payout, $user);

            return $this->to('payouts.show', $payout->id, 'payout_submitted');
        }

        return $this->to('payouts.show', $payout->id, 'payout_saved');
    }

    public function show(Request $request, PayoutRequest $payout): Response
    {
        $this->authorize('view', $payout);
        $user = $this->user($request);
        $approvalDenial = $this->payouts()->approvalDenial($payout, $user);
        $releaseDenial = $this->payouts()->releaseDenial($payout, $user);
        $canManage = $user->can('manage', $payout);

        return Inertia::render('payouts/Show', [
            'payout' => $this->detail($payout, $user, $this->role()),
            'can' => $this->pageCan([
                'edit' => $canManage && $payout->status === PayoutStatus::Draft,
                'submit' => $canManage && $payout->status === PayoutStatus::Draft,
                'resubmit' => $canManage && $payout->status === PayoutStatus::OnHold,
                'approve' => $approvalDenial === null,
                'reject' => $approvalDenial === null,
                'release' => $releaseDenial === null,
                'cancel' => $user->can('cancel', $payout) && in_array($payout->status, [PayoutStatus::Draft, PayoutStatus::PendingApproval, PayoutStatus::Approved, PayoutStatus::OnHold], true),
            ]),
            'cannot_approve_reason' => $payout->status === PayoutStatus::PendingApproval ? $approvalDenial : ($approvalDenial === null ? null : 'not_pending'),
            'cannot_release_reason' => in_array($releaseDenial, ['releaser_is_requester', 'release_delayed', 'not_approved', 'no_permission'], true) ? $releaseDenial : null,
            'disclaimer_url' => route('legal', ['doc' => 'payment-guard-disclaimer']),
        ]);
    }

    public function update(PayoutFormRequest $request, PayoutRequest $payout): RedirectResponse
    {
        $this->authorize('manage', $payout);
        $user = $this->user($request);
        $this->payouts()->updateDraft($payout, $user, $request->payload());

        if ($request->boolean('submit')) {
            $this->payouts()->submit($payout->refresh(), $user);

            return $this->to('payouts.show', $payout->id, 'payout_submitted');
        }

        return $this->to('payouts.show', $payout->id, 'payout_saved');
    }

    public function submit(Request $request, PayoutRequest $payout): RedirectResponse
    {
        $this->authorize('manage', $payout);
        $this->payouts()->submit($payout, $this->user($request));

        return $this->to('payouts.show', $payout->id, 'payout_submitted');
    }

    public function resubmit(Request $request, PayoutRequest $payout): RedirectResponse
    {
        $this->authorize('manage', $payout);
        $this->payouts()->resubmit($payout, $this->user($request));

        return $this->to('payouts.show', $payout->id, 'payout_resubmitted');
    }

    public function approve(Request $request, PayoutRequest $payout): RedirectResponse
    {
        $this->authorize('decide', $payout);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $this->refuseIneligible($payout, $request);
        $method = PayoutService::stepUpForApproval($payout) ? StepUpGate::require($request) : $this->secondFactorChecked($request);
        $this->payouts()->approve($payout, $this->user($request), DecisionContext::web($method, $request->ip(), $request->userAgent()), isset($data['comment']) ? (string) $data['comment'] : null);

        return $this->to('payouts.show', $payout->id, 'payout_approved');
    }

    public function reject(Request $request, PayoutRequest $payout): RedirectResponse
    {
        $this->authorize('decide', $payout);
        $data = $request->validate(['comment' => ['required', 'string', 'min:5', 'max:500']]);
        $method = $this->secondFactorChecked($request);
        $this->payouts()->reject($payout, $this->user($request), DecisionContext::web($method, $request->ip(), $request->userAgent()), (string) $data['comment']);

        return $this->to('payouts.show', $payout->id, 'payout_rejected');
    }

    public function release(Request $request, PayoutRequest $payout): RedirectResponse
    {
        $this->authorize('release', $payout);
        $data = $request->validate([
            'paid_reference' => ['required', 'string', 'min:1', 'max:140'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
        ]);

        if ($payout->approved_at !== null && (string) $data['paid_on'] < $payout->approved_at->copy()->setTimezone($this->workspace()->timezone)->toDateString()) {
            throw new GuardException('paid_on_before_approval', 422);
        }

        if (PayoutService::stepUpForRelease($payout)) {
            StepUpGate::require($request);
        } else {
            StepUpGate::assertSecondFactor($request);
        }

        $this->payouts()->release($payout, $this->user($request), ['paid_reference' => (string) $data['paid_reference'], 'paid_on' => (string) $data['paid_on']]);

        return $this->to('payouts.show', $payout->id, 'payout_released');
    }

    public function cancel(Request $request, PayoutRequest $payout): RedirectResponse
    {
        $this->authorize('cancel', $payout);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->payouts()->cancel($payout, $this->user($request), (string) $data['reason']);

        return $this->to('payouts.show', $payout->id, 'payout_canceled');
    }

    public function resendConfirmation(Request $request, PayoutRequest $payout, TrustedConfirmations $confirmations): RedirectResponse
    {
        $this->authorize('manage', $payout);
        $confirmation = TrustedConfirmation::query()->forWorkspace($payout->workspace_id)
            ->where('subject_type', TrustedSubjectType::PayoutRequest->value)
            ->where('subject_id', $payout->id)
            ->latest('id')
            ->first();

        if ($confirmation === null) {
            throw new GuardException('confirmation_not_pending');
        }

        $confirmations->resend($confirmation, $this->user($request));

        return $this->to('payouts.show', $payout->id, 'confirmation_resent');
    }

    /** @return array<string, mixed> */
    private function detail(PayoutRequest $payout, User $viewer, WorkspaceRole $role): array
    {
        $payout->load(['vendor', 'requester', 'bankAccount.requester', 'bankAccount.verifier', 'bankAccount.rejecter']);
        $confirmation = TrustedConfirmation::query()->forWorkspace($payout->workspace_id)
            ->with('contact')
            ->where('subject_type', TrustedSubjectType::PayoutRequest->value)
            ->where('subject_id', $payout->id)
            ->latest('id')
            ->first();

        return [
            ...GuardResource::payoutListItem($payout, $viewer, $role),
            'bank_account' => $payout->bankAccount !== null ? GuardResource::bankAccount($payout->bankAccount) : null,
            'current_account_matches' => $this->payouts()->bindingStillValid($payout),
            'risk_signals' => $payout->risk_signals ?? [],
            'telegram_allowed' => PayoutService::telegramApprovalAllowed($payout),
            'approval_round' => $payout->approval_round,
            'approvals' => PayoutApproval::query()->forWorkspace($payout->workspace_id)
                ->with('user')
                ->where('payout_request_id', $payout->id)
                ->orderBy('id')
                ->get()
                ->map(fn (PayoutApproval $approval): array => GuardResource::approvalRow($approval))
                ->all(),
            'eligible_approvers' => array_values(array_map(
                fn (EligibleMember $member): array => GuardResource::eligibleApprover($member, $payout),
                array_filter($this->eligibility()->members($payout->workspace_id, 'payouts.approve'), fn (EligibleMember $member): bool => $member->user->id !== $payout->requested_by),
            )),
            'trusted_confirmation' => GuardResource::confirmationInfo($confirmation),
            'release_requires_other_user' => $payout->release_requires_other_user,
            'release_not_before' => Iso::dateTime($payout->release_not_before),
            'split_group_total' => $payout->split_group_total_minor !== null ? MoneyData::of($payout->split_group_total_minor, $payout->currency) : null,
            'checks' => GuardResource::checks($payout, $viewer, $this->payouts()),
            'paid_reference' => $payout->paid_reference,
            'paid_on' => Iso::date($payout->paid_on),
            'released_by' => UserRef::of($payout->released_by !== null ? User::query()->find($payout->released_by) : null),
            'rejected_at' => Iso::dateTime($payout->rejected_at),
            'canceled_at' => Iso::dateTime($payout->canceled_at),
            'canceled_by' => UserRef::of($payout->canceled_by !== null ? User::query()->find($payout->canceled_by) : null),
            'cancel_reason' => $payout->cancel_reason,
            'timeline' => GuardResource::auditTimeline($payout),
        ];
    }

    /** @return array<string, mixed> */
    private function formProps(?PayoutRequest $payout, Request $request): array
    {
        $workspace = $this->workspace();
        $user = $this->user($request);
        $limits = app(PlanLimits::class);
        $now = app(Clock::class)->now();
        $vendors = Vendor::query()->forWorkspace($workspace)->where('status', VendorStatus::Active->value)->orderBy('name')->get();
        $pending = VendorBankAccount::query()->forWorkspace($workspace)->where('status', BankAccountStatus::PendingVerification->value)->pluck('vendor_id')->all();

        return [
            'payout' => $payout !== null ? $this->detail($payout, $user, $this->role()) : null,
            'vendors' => $vendors->map(function (Vendor $vendor) use ($pending): array {
                $account = $this->evaluator()->currentAccount($vendor);

                return [
                    ...GuardResource::vendorRef($vendor),
                    'currency' => $account?->currency,
                    'account_display' => $account !== null ? GuardResource::display($account) : null,
                    'account_status' => $account?->status->value,
                    'cooling_off_until' => Iso::dateTime($account?->cooling_off_until),
                    'pending_change' => in_array($vendor->id, $pending, true),
                ];
            })->all(),
            'thresholds' => GuardResource::guardSettings(WorkspaceProvisioner::settingsFor($workspace))['thresholds'],
            'limits' => ['payouts_per_month' => ['used' => $limits->used($workspace, 'payouts_per_month'), 'limit' => $limits->limit($workspace, 'payouts_per_month')]],
            'approvers_available' => [
                'eligible' => $this->eligibility()->others($workspace, 'payouts.approve', $user->id),
                'trusted_contact' => $this->evaluator()->activeTrustedContact($workspace->id, $now) !== null,
            ],
        ];
    }

    /** @return Builder<PayoutRequest> */
    private function tabQuery(Workspace $workspace, User $user, WorkspaceRole $role, string $tab, ?int $vendorId, ?string $search): Builder
    {
        $query = PayoutRequest::query()->forWorkspace($workspace);

        if ($vendorId !== null) {
            $query->where('vendor_id', $vendorId);
        }

        if ($search !== null && $search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereRaw('number ilike ?', [$like])
                    ->orWhereRaw('description ilike ?', [$like])
                    ->orWhereRaw('vendor_invoice_number ilike ?', [$like]);
            });
        }

        match ($tab) {
            'awaiting_me' => $this->awaitingMe($query, $user, $role),
            'pending' => $query->where('status', PayoutStatus::PendingApproval->value),
            'approved' => $query->where('status', PayoutStatus::Approved->value),
            'on_hold' => $query->where('status', PayoutStatus::OnHold->value),
            'released' => $query->where('status', PayoutStatus::Released->value),
            default => null,
        };

        return $query;
    }

    /** @param  Builder<PayoutRequest>  $query */
    private function awaitingMe(Builder $query, User $user, WorkspaceRole $role): void
    {
        if (! $role->allows('payouts.approve')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('status', PayoutStatus::PendingApproval->value)
            ->where('requested_by', '!=', $user->id)
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('payout_approvals')
                ->whereColumn('payout_approvals.payout_request_id', 'payout_requests.id')
                ->whereColumn('payout_approvals.approval_round', 'payout_requests.approval_round')
                ->where('payout_approvals.user_id', $user->id));
    }

    private function refuseIneligible(PayoutRequest $payout, Request $request): void
    {
        $denial = $this->payouts()->approvalDenial($payout, $this->user($request));

        if ($denial !== null && $denial !== 'details_changed') {
            throw new GuardException($denial, $denial === 'no_permission' ? 403 : 409);
        }
    }

    private function secondFactorChecked(Request $request): ?StepUpMethod
    {
        StepUpGate::assertSecondFactor($request);

        return StepUpGate::method($request);
    }

    private function payouts(): PayoutService
    {
        return app(PayoutService::class);
    }

    private function evaluator(): PayoutEvaluator
    {
        return app(PayoutEvaluator::class);
    }

    private function eligibility(): GuardEligibility
    {
        return app(GuardEligibility::class);
    }
}
