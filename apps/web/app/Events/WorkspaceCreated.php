<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

final class WorkspaceCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $owner,
    ) {}
}
