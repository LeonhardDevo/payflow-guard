<?php

declare(strict_types=1);

namespace App\Http\Middleware\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Analytics\GuardSummary;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\StepUp\StepUpGate;
use App\Domain\Payments\Links\PaymentAccountState;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class PayflowSharedProps
{
    public const array ENTITLEMENTS = [
        'payment_links', 'telegram_bot', 'payment_guard', 'rag_assistant', 'csv_import', 'risk_scoring', 'custom_templates', 'ai_drafts',
        'analytics_advanced', 'audit_export',
    ];

    public function __construct(
        private CurrentWorkspace $current,
        private PlanLimits $limits,
        private PaymentAccountState $accounts,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed>|null */
    public function build(Request $request): ?array
    {
        $user = $request->user();
        $workspace = $user instanceof User ? $this->current->get() : null;
        $role = $this->current->role();

        if (! $user instanceof User || $workspace === null || $role === null) {
            return null;
        }

        $cached = Cache::remember(
            'pf-shared:'.$workspace->id.':'.$user->id,
            30,
            fn (): array => [
                'nav_counts' => $this->navCounts($workspace, $user, $role),
                'payment_account' => $this->accounts->shared($workspace),
                'entitlements' => $this->entitlements($workspace),
            ],
        );

        $membership = DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->value('guard_eligible_from');
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $upgrade = $request->hasSession() ? $request->session()->get('pf_upgrade') : null;

        return [
            ...$cached,
            'upgrade' => is_array($upgrade) ? $upgrade : null,
            'stepup' => [
                'fresh_until' => StepUpGate::freshUntil($request),
                'methods' => StepUpGate::methods($user, $workspace),
                'second_factor_required' => $settings->require_second_factor,
                'has_second_factor' => GuardEligibility::hasSecondFactor($user),
                'prompt' => $request->hasSession() && $request->session()->get('pf_step_up_required') === true,
            ],
            'guard_error' => $request->hasSession() && is_array($request->session()->get('pf_guard_error')) ? $request->session()->get('pf_guard_error') : null,
            'guard' => [
                'privileged' => $role->isGuardPrivileged(),
                'eligible_from' => is_string($membership) ? Iso::dateTime(CarbonImmutable::parse($membership)) : null,
            ],
        ];
    }

    /** @return array<string, int> */
    private function navCounts(Workspace $workspace, User $user, WorkspaceRole $role): array
    {
        $today = WorkspaceClock::todayIn($workspace->timezone, $this->clock->now())->toDateString();
        $myApprovals = 0;

        if ($role->allows('payouts.approve')) {
            $decided = PayoutApproval::query()->forWorkspace($workspace)->where('user_id', $user->id)->pluck('payout_request_id')->all();
            $myApprovals = PayoutRequest::query()->forWorkspace($workspace)
                ->where('status', PayoutStatus::PendingApproval->value)
                ->where('requested_by', '!=', $user->id)
                ->whereNotIn('id', $decided)
                ->count();
        }

        return [
            'overdue_invoices' => $role->allows('invoices.view') ? Invoice::query()->forWorkspace($workspace)
                ->whereIn('status', Invoice::OPEN_STATUSES)
                ->where('due_date', '<', $today)
                ->count() : 0,
            'failed_reminders' => $role->allows('invoices.view') ? Reminder::query()->forWorkspace($workspace)
                ->where('status', ReminderStatus::Failed->value)
                ->count() : 0,
            'critical_alerts' => $role->allows('vendors.view') || $role->allows('payouts.view') ? GuardSummary::criticalCount($workspace) : 0,
            'my_approvals' => $myApprovals,
            'pending_bank_changes' => $role->allows('vendors.view') || $role->allows('bank_changes.verify') ? VendorBankAccount::query()->forWorkspace($workspace)
                ->where('status', BankAccountStatus::PendingVerification->value)
                ->count() : 0,
        ];
    }

    /** @return array<string, bool> */
    private function entitlements(Workspace $workspace): array
    {
        $result = [];

        foreach (self::ENTITLEMENTS as $key) {
            $result[$key] = $this->limits->hasFeature($workspace, $key);
        }

        return $result;
    }
}
