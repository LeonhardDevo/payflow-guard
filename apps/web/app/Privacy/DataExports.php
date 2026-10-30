<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Audit\Auditor;
use App\Mail\DataExportReadyMail;
use App\Models\DataExport;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

final readonly class DataExports
{
    public function __construct(
        private DataExportBuilder $builder,
        private Outbox $outbox,
        private Auditor $auditor,
    ) {}

    public static function disk(): string
    {
        return (string) config('filesystems.exports_disk', 'local');
    }

    /** @throws PrivacyException */
    public function request(User $user, string $scope, ?Workspace $workspace = null): DataExport
    {
        $workspace = $scope === DataExport::SCOPE_WORKSPACE ? $workspace : null;

        if ($scope === DataExport::SCOPE_WORKSPACE && $workspace === null) {
            throw new PrivacyException('workspace_required');
        }

        $workspaceId = $workspace?->id;
        $label = $workspace !== null ? $workspace->name : $user->email;

        $running = DataExport::query()
            ->where('user_id', $user->id)
            ->where('scope', $scope)
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', [DataExport::PENDING, DataExport::PROCESSING])
            ->where('created_at', '>', now()->subDay())
            ->exists();

        if ($running) {
            throw new PrivacyException('export_in_progress');
        }

        return DB::transaction(function () use ($user, $scope, $workspaceId, $workspace, $label): DataExport {
            $export = DataExport::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'scope' => $scope,
                'status' => DataExport::PENDING,
            ]);

            $this->outbox->publish(Topics::PRIVACY_EXPORT_REQUESTED, ['export_id' => $export->id], $workspaceId);

            $this->auditor->record(
                $scope === DataExport::SCOPE_WORKSPACE ? 'privacy.workspace_export_requested' : 'privacy.export_requested',
                $scope === DataExport::SCOPE_WORKSPACE ? $workspace : $user,
                metadata: ['export_id' => $export->id],
                actor: $user,
                workspace: $workspace,
                subjectLabel: $label,
                platform: $scope !== DataExport::SCOPE_WORKSPACE,
            );

            return $export;
        });
    }

    public function build(DataExport $export): DataExport
    {
        if (! in_array($export->status, [DataExport::PENDING, DataExport::PROCESSING, DataExport::FAILED], true)) {
            return $export;
        }

        $export->forceFill(['status' => DataExport::PROCESSING, 'error' => null])->save();
        $temporary = null;

        try {
            $temporary = $this->builder->build($export);
            $path = 'privacy-exports/'.$export->id.'-'.Str::random(40).'.zip';
            $stream = fopen($temporary, 'rb');

            if ($stream === false) {
                throw new \RuntimeException('Cannot read the export archive.');
            }

            try {
                Storage::disk(self::disk())->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }

            $export->forceFill([
                'status' => DataExport::READY,
                'disk' => self::disk(),
                'path' => $path,
                'size_bytes' => (int) filesize($temporary),
                'completed_at' => now(),
                'expires_at' => now()->addHours((int) config('retention.export_link_hours', 24)),
            ])->save();
        } catch (Throwable $exception) {
            Log::error('privacy export failed', ['export_id' => $export->id, 'error' => $exception->getMessage()]);
            $export->forceFill(['status' => DataExport::FAILED, 'error' => Str::limit($exception->getMessage(), 500)])->save();
            $this->audit('privacy.export_failed', $export);

            return $export;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
        }

        $this->audit('privacy.export_ready', $export, ['size_bytes' => $export->size_bytes]);
        Mail::to($export->user)->send(new DataExportReadyMail($export, $this->downloadUrl($export)));

        return $export;
    }

    public function downloadUrl(DataExport $export): ?string
    {
        if (! $export->isDownloadable() || $export->expires_at === null) {
            return null;
        }

        return URL::temporarySignedRoute('settings.privacy.exports.download', $export->expires_at, ['export' => $export->id]);
    }

    public function markDownloaded(DataExport $export): void
    {
        $export->forceFill(['downloaded_at' => now()])->save();
        $this->audit('privacy.export_downloaded', $export);
    }

    public function purgeExpired(): int
    {
        $count = 0;

        DataExport::query()
            ->where('status', DataExport::READY)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (DataExport $export) use (&$count): void {
                if ($export->disk !== null && $export->path !== null) {
                    Storage::disk($export->disk)->delete($export->path);
                }

                $export->forceFill(['status' => DataExport::EXPIRED, 'path' => null])->save();
                $count++;
            });

        return $count;
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(string $action, DataExport $export, array $metadata = []): void
    {
        $workspace = $export->scope === DataExport::SCOPE_WORKSPACE ? $export->workspace : null;

        $this->auditor->record(
            $action,
            $workspace ?? $export->user,
            metadata: ['export_id' => $export->id, 'scope' => $export->scope, ...$metadata],
            actor: $export->user,
            workspace: $workspace,
            subjectLabel: $workspace !== null ? $workspace->name : $export->user->email,
            platform: $workspace === null,
        );
    }
}
