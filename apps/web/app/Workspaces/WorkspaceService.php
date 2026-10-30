<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Events\WorkspaceCreated;
use App\Knowledge\DocumentService;
use App\Models\Document;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\Contracts\WorkspacePurgeHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class WorkspaceService
{
    public function __construct(
        private Auditor $auditor,
        private CurrentWorkspace $currentWorkspace,
        private Outbox $outbox,
    ) {}

    /** @param  array{name: string, timezone: string, currency: string, country?: string|null, locale?: string|null}  $data */
    public function create(User $owner, array $data): Workspace
    {
        $workspace = DB::transaction(function () use ($owner, $data): Workspace {
            $workspace = Workspace::query()->create([
                'name' => $data['name'],
                'slug' => self::uniqueSlug($data['name']),
                'timezone' => $data['timezone'],
                'currency' => strtoupper($data['currency']),
                'country' => isset($data['country']) ? strtoupper($data['country']) : null,
                'locale' => $data['locale'] ?? $owner->locale,
                'created_by' => $owner->id,
            ]);

            $workspace->addMember($owner, WorkspaceRole::Owner);

            $this->auditor->record('workspace.created', $workspace, actor: $owner, workspace: $workspace, subjectLabel: $workspace->name);

            DB::afterCommit(fn () => WorkspaceCreated::dispatch($workspace, $owner));

            return $workspace;
        });

        $this->currentWorkspace->switchTo($owner, $workspace);

        return $workspace;
    }

    /** @param  array{name?: string, timezone?: string, currency?: string, country?: string|null, locale?: string}  $data */
    public function update(Workspace $workspace, User $actor, array $data): Workspace
    {
        $fields = ['name', 'timezone', 'currency', 'country', 'locale'];
        $before = $workspace->only($fields);

        if (array_key_exists('country', $data) && $data['country'] === null) {
            unset($data['country']);
        }

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $workspace->fill(array_intersect_key($data, array_flip($fields)));

        if ($workspace->isDirty()) {
            DB::transaction(function () use ($workspace, $actor, $before, $fields): void {
                $workspace->save();
                $this->auditor->record(
                    'workspace.updated',
                    $workspace,
                    Auditor::diff($before, $workspace->only($fields)),
                    actor: $actor,
                    subjectLabel: $workspace->name,
                );
            });
        }

        return $workspace;
    }

    public function delete(Workspace $workspace, ?User $actor): void
    {
        $disks = Document::query()->forWorkspace($workspace)->distinct()->pluck('disk')->all();

        foreach ((array) config('product.privacy.workspace_purge_hooks', []) as $class) {
            $hook = is_string($class) ? app($class) : null;

            if ($hook instanceof WorkspacePurgeHook) {
                $hook->beforePurge($workspace);
            }
        }

        DB::transaction(function () use ($workspace, $actor): void {
            $this->auditor->record('workspace.deleted', $workspace, actor: $actor, subjectLabel: $workspace->name);

            OutboxMessage::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('dispatched_at')
                ->delete();

            $this->outbox->publish(Topics::WORKSPACE_PURGE, ['workspace_id' => $workspace->id], $workspace->id);

            TelegramAccount::query()->where('workspace_id', $workspace->id)->update(['workspace_id' => null]);
            $workspace->delete();
        });

        foreach (array_unique([DocumentService::disk(), ...$disks]) as $disk) {
            Storage::disk((string) $disk)->deleteDirectory((string) $workspace->id);
        }

        $this->currentWorkspace->forget();
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug(Str::limit($name, 60, '')) ?: 'workspace';
        $slug = $base;

        while (Workspace::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
