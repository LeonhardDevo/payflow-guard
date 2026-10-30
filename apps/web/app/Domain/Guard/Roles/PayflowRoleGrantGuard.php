<?php

declare(strict_types=1);

namespace App\Domain\Guard\Roles;

use App\Audit\Auditor;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Guard\StepUp\StepUpGate;
use App\Domain\Guard\StepUp\StepUpRequired;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\Clock;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Contracts\RoleGrantGuard;
use App\Workspaces\WorkspaceException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class PayflowRoleGrantGuard implements RoleGrantGuard
{
    public function __construct(
        private Clock $clock,
        private Auditor $auditor,
        private GuardAlerts $alerts,
        private GuardNotifications $notifications,
        private PayoutService $payouts,
    ) {}

    public function assertCanGrant(Workspace $workspace, User $actor, WorkspaceRole $role): void
    {
        if (! $role->isGuardPrivileged()) {
            return;
        }

        if ($actor->roleIn($workspace) !== WorkspaceRole::Owner) {
            throw new WorkspaceException('insufficient_role');
        }

        $request = app(Request::class);

        if (! StepUpGate::isFresh($request)) {
            throw new StepUpRequired('step_up_required', StepUpGate::methods($actor, $workspace));
        }
    }

    public function granted(Workspace $workspace, User $member, ?WorkspaceRole $from, WorkspaceRole $to, User $actor): void
    {
        if (! $to->isGuardPrivileged()) {
            DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $member->id)->update(['guard_eligible_from' => null]);

            if ($from !== null && $from->isGuardPrivileged()) {
                $this->revoked($workspace, $member, $from, $to, $actor);
            }

            return;
        }

        $now = $this->clock->now();
        $eligibleFrom = $now->addHours(WorkspaceProvisioner::settingsFor($workspace)->guard_trust_delay_hours);
        DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $member->id)->update(['guard_eligible_from' => $eligibleFrom]);

        $this->auditor->record('payflow.member.guard_role_granted', $member, metadata: [
            'role' => $to->value,
            'from' => $from?->value,
            'eligible_from' => Iso::dateTime($eligibleFrom),
        ], actor: $actor, workspace: $workspace, subjectLabel: $member->name);

        $alert = $this->alerts->ensure($workspace->id, AlertType::GuardRoleGranted, AlertSubjectType::WorkspaceMember, $member->id, 'role:'.$member->id.':'.$now->getTimestamp(), [
            'actor' => UserRef::name($actor),
            'member' => UserRef::name($member),
            'role' => $to->value,
            'eligible_from' => (string) Iso::dateTime($eligibleFrom),
        ]);
        $this->notifications->guardRoleGranted($workspace, $actor, $member, $to, $eligibleFrom, $alert);
    }

    public function revoked(Workspace $workspace, User $member, WorkspaceRole $from, ?WorkspaceRole $to, User $actor): void
    {
        if (! $from->isGuardPrivileged()) {
            return;
        }

        $now = $this->clock->now();
        $this->auditor->record('payflow.member.guard_role_revoked', $member, metadata: ['role' => $from->value, 'to' => $to?->value], actor: $actor, workspace: $workspace, subjectLabel: $member->name);
        $alert = $this->alerts->ensure($workspace->id, AlertType::GuardRoleRevoked, AlertSubjectType::WorkspaceMember, $member->id, 'role-rv:'.$member->id.':'.$now->getTimestamp(), [
            'actor' => UserRef::name($actor),
            'member' => UserRef::name($member),
            'role' => $from->value,
        ]);
        $this->alerts->resolve($workspace->id, '2fa:'.$member->id, $actor);
        $this->payouts->invalidateForMember($workspace->id, $member, $actor);
        $this->notifications->guardRoleRevoked($workspace, $actor, $member, $from, $alert);
    }
}
