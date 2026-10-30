<?php

declare(strict_types=1);

namespace App\Workspaces\Contracts;

use App\Models\Workspace;

interface WorkspacePurgeHook
{
    public function beforePurge(Workspace $workspace): void;
}
