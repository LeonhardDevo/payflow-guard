<?php

declare(strict_types=1);

namespace App\Domain\Guard\StepUp;

use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Shared\Support\UserRef;
use App\Models\User;
use App\Models\Workspace;

final readonly class SecondFactorAlerts
{
    public function __construct(
        private GuardAlerts $alerts,
        private GuardNotifications $notifications,
    ) {}

    public function raise(Workspace $workspace, User $user): void
    {
        $alert = $this->alerts->ensure(
            $workspace->id,
            AlertType::SecondFactorMissing,
            AlertSubjectType::WorkspaceMember,
            $user->id,
            '2fa:'.$user->id,
            ['user' => UserRef::name($user)],
        );

        if ($alert->wasRecentlyCreated) {
            $this->notifications->secondFactorMissing($workspace->id, $user);
        }
    }

    public function resolve(Workspace|int $workspace, User $user): void
    {
        $this->alerts->resolve($workspace instanceof Workspace ? $workspace->id : $workspace, '2fa:'.$user->id);
    }
}
