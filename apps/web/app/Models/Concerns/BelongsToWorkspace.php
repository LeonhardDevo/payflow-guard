<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use App\Tenancy\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @mixin Model */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') === null) {
                $workspaceId = app(CurrentWorkspace::class)->id();

                if ($workspaceId === null) {
                    throw new \LogicException(sprintf(
                        'Cannot create %s without a workspace: set workspace_id or resolve a current workspace.',
                        $model::class,
                    ));
                }

                $model->setAttribute('workspace_id', $workspaceId);
            }
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): void
    {
        $query->withoutGlobalScope(WorkspaceScope::class)
            ->where($this->qualifyColumn('workspace_id'), $workspace instanceof Workspace ? $workspace->id : $workspace);
    }
}
