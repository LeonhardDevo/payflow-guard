<?php

declare(strict_types=1);

namespace App\Domain\Guard\Eligibility;

use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class GuardEligibility
{
    public function __construct(private Clock $clock) {}

    /** @return list<EligibleMember> */
    public function members(Workspace|int $workspace, string $ability): array
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;
        $requireSecondFactor = WorkspaceProvisioner::settingsFor($workspace)->require_second_factor;
        $rows = DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $workspaceId)
            ->whereNull('users.pseudonymised_at')
            ->orderBy('users.id')
            ->get(['workspace_user.user_id', 'workspace_user.role', 'workspace_user.guard_eligible_from']);

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->all())->get()->keyBy('id');
        $result = [];

        foreach ($rows as $row) {
            $role = WorkspaceRole::tryFrom((string) $row->role);
            $user = $users->get((int) $row->user_id);

            if ($role === null || ! $user instanceof User || ! $role->allows($ability)) {
                continue;
            }

            $result[] = $this->member($user, $role, is_string($row->guard_eligible_from) ? $row->guard_eligible_from : null, $requireSecondFactor);
        }

        return $result;
    }

    public function others(Workspace|int $workspace, string $ability, ?int $exceptUserId): int
    {
        return count(array_filter(
            $this->members($workspace, $ability),
            fn (EligibleMember $member): bool => $member->eligible() && $member->user->id !== $exceptUserId,
        ));
    }

    public function find(Workspace|int $workspace, User $user): ?EligibleMember
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;
        $row = DB::table('workspace_user')->where('workspace_id', $workspaceId)->where('user_id', $user->id)->first(['role', 'guard_eligible_from']);

        if ($row === null) {
            return null;
        }

        $role = WorkspaceRole::tryFrom((string) $row->role);

        if ($role === null) {
            return null;
        }

        return $this->member(
            $user,
            $role,
            is_string($row->guard_eligible_from) ? $row->guard_eligible_from : null,
            WorkspaceProvisioner::settingsFor($workspace)->require_second_factor,
        );
    }

    public function denial(Workspace|int $workspace, User $user, string $ability): ?string
    {
        $member = $this->find($workspace, $user);

        if ($member === null || ! $member->role->allows($ability)) {
            return 'no_permission';
        }

        return $member->reason();
    }

    public static function hasSecondFactor(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null || $user->passkeys()->exists();
    }

    private function member(User $user, WorkspaceRole $role, ?string $eligibleFrom, bool $requireSecondFactor): EligibleMember
    {
        $from = $eligibleFrom !== null ? CarbonImmutable::parse($eligibleFrom)->utc() : null;
        $privileged = $role->isGuardPrivileged();

        return new EligibleMember(
            user: $user,
            role: $role,
            eligibleFrom: $from,
            missingSecondFactor: $privileged && $requireSecondFactor && ! self::hasSecondFactor($user),
            trustDelayPassed: ! $privileged || ($from !== null && $from->lte($this->clock->now())),
        );
    }
}
