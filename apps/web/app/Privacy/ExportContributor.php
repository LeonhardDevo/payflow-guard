<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Models\User;
use App\Models\Workspace;

interface ExportContributor
{
    /** @return array<string, mixed> file name (without .json) => JSON-serialisable data */
    public function forUser(User $user): array;

    /** @return array<string, mixed> file name (without .json) => JSON-serialisable data */
    public function forWorkspace(Workspace $workspace): array;
}
