<?php

declare(strict_types=1);

use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Workspace::query()->orderBy('id')->each(fn (Workspace $workspace) => WorkspaceProvisioner::provision($workspace));
    }

    public function down(): void {}
};
