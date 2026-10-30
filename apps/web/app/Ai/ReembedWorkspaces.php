<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Illuminate\Support\Facades\DB;

final readonly class ReembedWorkspaces
{
    public function __construct(private Outbox $outbox) {}

    public function publishAll(): int
    {
        return DB::transaction(function (): int {
            $count = 0;

            foreach (Workspace::query()->select(['id', 'ai_external_allowed'])->lazyById(500) as $workspace) {
                $this->outbox->publish(Topics::WORKSPACE_REEMBED, [
                    'workspace_id' => $workspace->id,
                    'ai_external_allowed' => (bool) $workspace->ai_external_allowed,
                ], $workspace->id);
                $count++;
            }

            return $count;
        });
    }
}
