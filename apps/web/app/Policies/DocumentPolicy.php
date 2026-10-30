<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;

final class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('knowledge.view');
    }

    public function view(User $user, Document $document): bool
    {
        return $this->sameWorkspace($user, $document) && $user->can('knowledge.view');
    }

    public function create(User $user): bool
    {
        return $user->can('knowledge.manage');
    }

    public function update(User $user, Document $document): bool
    {
        return $this->sameWorkspace($user, $document) && $user->can('knowledge.manage');
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    private function sameWorkspace(User $user, Document $document): bool
    {
        return $user->belongsToWorkspace($document->workspace_id)
            && app(CurrentWorkspace::class)->id() === $document->workspace_id;
    }
}
