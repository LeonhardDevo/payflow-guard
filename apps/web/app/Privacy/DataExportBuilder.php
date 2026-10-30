<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Models\DataExport;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final readonly class DataExportBuilder
{
    public const int FORMAT_VERSION = 1;

    public function __construct(private Container $container) {}

    public function build(DataExport $export): string
    {
        [$sections, $files] = match ($export->scope) {
            DataExport::SCOPE_WORKSPACE => $this->workspaceData($this->workspace($export)),
            default => $this->userData($export->user),
        };

        $path = tempnam(sys_get_temp_dir(), 'export-');

        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file for the export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open the export archive.');
        }

        $manifest = [
            'format_version' => self::FORMAT_VERSION,
            'product' => config('app.name'),
            'scope' => $export->scope,
            'export_id' => $export->id,
            'subject' => $export->scope === DataExport::SCOPE_WORKSPACE
                ? ['type' => 'workspace', 'id' => $export->workspace_id]
                : ['type' => 'user', 'id' => $export->user_id],
            'generated_at' => now()->toIso8601String(),
            'files' => [],
        ];

        foreach ($sections as $name => $data) {
            $zip->addFromString($name.'.json', self::json($data));
            $manifest['files'][] = $name.'.json';
        }

        foreach ($files as $file) {
            $disk = Storage::disk($file['disk']);

            if (! $disk->exists($file['path'])) {
                continue;
            }

            $entry = $file['name'];
            $zip->addFromString($entry, (string) $disk->get($file['path']));
            $manifest['files'][] = $entry;
        }

        $zip->addFromString('manifest.json', self::json($manifest));
        $zip->close();

        return $path;
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array{id: int, disk: string, path: string, name: string}>} */
    public function userData(User $user): array
    {
        $documents = Document::query()->withoutGlobalScopes()->where('uploaded_by', $user->id)->orderBy('id')->get();

        $sections = [
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
            'workspaces' => DB::table('workspace_user')
                ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
                ->where('workspace_user.user_id', $user->id)
                ->orderBy('workspaces.id')
                ->get(['workspaces.id', 'workspaces.name', 'workspace_user.role', 'workspace_user.created_at as joined_at'])
                ->all(),
            'telegram' => DB::table('telegram_accounts')
                ->where('user_id', $user->id)
                ->get(['telegram_user_id', 'chat_id', 'username', 'language_code', 'linked_at'])
                ->all(),
            'legal_acceptances' => DB::table('legal_acceptances')
                ->where('user_id', $user->id)
                ->orderBy('accepted_at')
                ->get(['document', 'version', 'locale', 'workspace_id', 'accepted_at', 'ip', 'user_agent'])
                ->all(),
            'ai_queries' => DB::table('ai_queries')
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get(['workspace_id', 'channel', 'question', 'answer', 'refused', 'citations', 'provider', 'model', 'created_at'])
                ->map(self::decodeJson(['citations']))
                ->all(),
            'activity' => DB::table('audit_logs')
                ->where('actor_id', $user->id)
                ->orderBy('id')
                ->get(['workspace_id', 'action', 'subject_type', 'subject_label', 'ip', 'user_agent', 'created_at'])
                ->all(),
            'notifications' => DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->orderBy('created_at')
                ->get(['data', 'read_at', 'created_at'])
                ->map(self::decodeJson(['data']))
                ->all(),
            'documents' => $documents->map(self::documentRow(...))->all(),
            'deletion_requests' => DB::table('deletion_requests')
                ->where('subject_type', 'user')
                ->where('subject_id', $user->id)
                ->get(['scheduled_for', 'cancelled_at', 'completed_at', 'created_at'])
                ->all(),
        ];

        foreach ($this->contributors() as $contributor) {
            $sections = [...$sections, ...$contributor->forUser($user)];
        }

        return [$sections, self::fileList($documents)];
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array{id: int, disk: string, path: string, name: string}>} */
    public function workspaceData(Workspace $workspace): array
    {
        $documents = Document::query()->forWorkspace($workspace)->orderBy('id')->get();

        $sections = [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'timezone' => $workspace->timezone,
                'currency' => $workspace->currency,
                'locale' => $workspace->locale,
                'ai_external_allowed' => $workspace->ai_external_allowed,
                'retention' => $workspace->retention,
                'plan' => $workspace->planKey(),
                'created_at' => $workspace->created_at?->toIso8601String(),
            ],
            'members' => DB::table('workspace_user')
                ->join('users', 'users.id', '=', 'workspace_user.user_id')
                ->where('workspace_user.workspace_id', $workspace->id)
                ->orderBy('users.id')
                ->get(['users.id', 'users.name', 'users.email', 'workspace_user.role', 'workspace_user.created_at as joined_at'])
                ->all(),
            'invitations' => DB::table('workspace_invitations')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(['email', 'role', 'expires_at', 'accepted_at', 'created_at'])
                ->all(),
            'subscription' => DB::table('subscriptions')
                ->where('workspace_id', $workspace->id)
                ->first(['plan', 'status', 'interval', 'current_period_end', 'cancel_at_period_end', 'trial_ends_at', 'canceled_at']),
            'documents' => $documents->map(self::documentRow(...))->all(),
            'ai_queries' => DB::table('ai_queries')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(['user_id', 'channel', 'question', 'answer', 'refused', 'confidence', 'citations', 'provider', 'model', 'created_at'])
                ->map(self::decodeJson(['citations']))
                ->all(),
            'audit_log' => DB::table('audit_logs')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(['actor_name', 'action', 'subject_type', 'subject_label', 'changes', 'created_at'])
                ->map(self::decodeJson(['changes']))
                ->all(),
            'legal_acceptances' => DB::table('legal_acceptances')
                ->where('workspace_id', $workspace->id)
                ->orderBy('accepted_at')
                ->get(['user_id', 'document', 'version', 'locale', 'accepted_at'])
                ->all(),
        ];

        foreach ($this->contributors() as $contributor) {
            $sections = [...$sections, ...$contributor->forWorkspace($workspace)];
        }

        return [$sections, self::fileList($documents)];
    }

    /** @return list<ExportContributor> */
    private function contributors(): array
    {
        $contributors = [];

        foreach ((array) config('product.privacy.export_contributors', []) as $class) {
            $instance = is_string($class) ? $this->container->make($class) : null;

            if ($instance instanceof ExportContributor) {
                $contributors[] = $instance;
            }
        }

        return $contributors;
    }

    private function workspace(DataExport $export): Workspace
    {
        $workspace = $export->workspace;

        if ($workspace === null) {
            throw new RuntimeException("Workspace of export {$export->id} no longer exists.");
        }

        return $workspace;
    }

    /** @return array<string, mixed> */
    private static function documentRow(Document $document): array
    {
        return [
            'id' => $document->id,
            'workspace_id' => $document->workspace_id,
            'title' => $document->title,
            'original_name' => $document->original_name,
            'mime' => $document->mime,
            'size_bytes' => $document->size_bytes,
            'sha256' => $document->sha256,
            'department' => $document->department,
            'roles' => $document->roles,
            'tags' => $document->tags,
            'version' => $document->version,
            'effective_date' => $document->effective_date?->toDateString(),
            'status' => $document->status->value,
            'created_at' => $document->created_at?->toIso8601String(),
            'file' => self::entryName($document),
        ];
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return array<int, array{id: int, disk: string, path: string, name: string}>
     */
    private static function fileList(Collection $documents): array
    {
        return $documents->map(fn (Document $document): array => [
            'id' => $document->id,
            'disk' => $document->disk,
            'path' => $document->path,
            'name' => self::entryName($document),
        ])->values()->all();
    }

    private static function entryName(Document $document): string
    {
        return 'files/'.$document->id.'-'.self::safeName($document->original_name);
    }

    /** @param  list<string>  $columns */
    private static function decodeJson(array $columns): \Closure
    {
        return static function (object $row) use ($columns): object {
            foreach ($columns as $column) {
                if (isset($row->{$column}) && is_string($row->{$column})) {
                    $row->{$column} = json_decode($row->{$column}, true);
                }
            }

            return $row;
        };
    }

    public static function safeName(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'file';
        $extension = preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '';

        return Str::limit($base, 80, '').($extension !== '' ? '.'.strtolower($extension) : '');
    }

    private static function json(mixed $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
