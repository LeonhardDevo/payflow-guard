<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Audit\AuditMaintenance;
use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Mail\DeletionScheduledMail;
use App\Models\DataExport;
use App\Models\DeletionRequest;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use App\Workspaces\AccountDeletion;
use App\Workspaces\WorkspaceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class DeletionRequests
{
    public function __construct(
        private AccountDeletion $accounts,
        private WorkspaceService $workspaces,
        private Auditor $auditor,
        private Outbox $outbox,
    ) {}

    public function pendingFor(string $type, int $id): ?DeletionRequest
    {
        return DeletionRequest::query()->pending()->for($type, $id)->first();
    }

    /** @throws PrivacyException */
    public function scheduleUser(User $user): DeletionRequest
    {
        $blocking = $this->accounts->blockingWorkspaces($user)->first();

        if ($blocking !== null) {
            throw new PrivacyException('transfer_ownership_first', ['workspace' => $blocking->name]);
        }

        $existing = $this->pendingFor(DeletionRequest::USER, $user->id);

        if ($existing !== null) {
            return $existing;
        }

        $request = DB::transaction(function () use ($user): DeletionRequest {
            $request = DeletionRequest::query()->create([
                'subject_type' => DeletionRequest::USER,
                'subject_id' => $user->id,
                'requested_by' => $user->id,
                'scheduled_for' => now()->addDays((int) config('retention.account_deletion_grace', 14)),
            ]);

            $this->auditor->record(
                'privacy.deletion_scheduled',
                $user,
                metadata: ['scheduled_for' => $request->scheduled_for->toIso8601String()],
                actor: $user,
                workspace: null,
                subjectLabel: $user->email,
                platform: true,
            );

            return $request;
        });

        Mail::to($user)->send(new DeletionScheduledMail($request, $user->name, null));

        return $request;
    }

    /** @throws PrivacyException */
    public function cancelUser(User $user): DeletionRequest
    {
        $request = $this->pendingFor(DeletionRequest::USER, $user->id) ?? throw new PrivacyException('nothing_to_cancel');

        DB::transaction(function () use ($request, $user): void {
            $request->forceFill(['cancelled_at' => now()])->save();
            $this->auditor->record('privacy.deletion_cancelled', $user, actor: $user, workspace: null, subjectLabel: $user->email, platform: true);
        });

        return $request;
    }

    public function scheduleWorkspace(Workspace $workspace, User $actor): DeletionRequest
    {
        $existing = $this->pendingFor(DeletionRequest::WORKSPACE, $workspace->id);

        if ($existing !== null) {
            return $existing;
        }

        $request = DB::transaction(function () use ($workspace, $actor): DeletionRequest {
            $request = DeletionRequest::query()->create([
                'subject_type' => DeletionRequest::WORKSPACE,
                'subject_id' => $workspace->id,
                'requested_by' => $actor->id,
                'scheduled_for' => now()->addDays((int) config('retention.workspace_deletion_grace', 30)),
            ]);

            $this->auditor->record(
                'workspace.deletion_scheduled',
                $workspace,
                metadata: ['scheduled_for' => $request->scheduled_for->toIso8601String()],
                actor: $actor,
                subjectLabel: $workspace->name,
            );

            return $request;
        });

        foreach ($this->owners($workspace) as $owner) {
            Mail::to($owner)->send(new DeletionScheduledMail($request, $owner->name, $workspace->name));
        }

        return $request;
    }

    /** @throws PrivacyException */
    public function cancelWorkspace(Workspace $workspace, User $actor): DeletionRequest
    {
        $request = $this->pendingFor(DeletionRequest::WORKSPACE, $workspace->id) ?? throw new PrivacyException('nothing_to_cancel');

        DB::transaction(function () use ($request, $workspace, $actor): void {
            $request->forceFill(['cancelled_at' => now()])->save();
            $this->auditor->record('workspace.deletion_cancelled', $workspace, actor: $actor, subjectLabel: $workspace->name);
        });

        return $request;
    }

    /** @return array{users: int, workspaces: int, blocked: int, failed: int} */
    public function runDue(): array
    {
        $counts = ['users' => 0, 'workspaces' => 0, 'blocked' => 0, 'failed' => 0];

        $due = DeletionRequest::query()
            ->pending()
            ->where('scheduled_for', '<=', now())
            ->orderByRaw("CASE subject_type WHEN 'workspace' THEN 0 ELSE 1 END")
            ->orderBy('scheduled_for')
            ->get();

        foreach ($due as $request) {
            try {
                $outcome = $request->subject_type === DeletionRequest::WORKSPACE
                    ? $this->purgeWorkspace($request)
                    : $this->purgeUser($request);
            } catch (Throwable $exception) {
                Log::error('privacy deletion failed', ['request_id' => $request->id, 'error' => $exception->getMessage()]);
                $counts['failed']++;

                continue;
            }

            $counts[$outcome]++;
        }

        return $counts;
    }

    /** @return 'users'|'blocked' */
    private function purgeUser(DeletionRequest $request): string
    {
        $user = User::query()->find($request->subject_id);

        if ($user === null) {
            $request->forceFill(['completed_at' => now()])->save();

            return 'users';
        }

        if ($this->accounts->blockingWorkspaces($user)->isNotEmpty()) {
            return 'blocked';
        }

        $userId = $user->id;
        $telegramUserIds = TelegramAccount::query()->where('user_id', $userId)->pluck('telegram_user_id')
            ->map(fn (mixed $id): int => (int) $id)->values()->all();
        $this->deleteExportFiles(DataExport::query()->where('user_id', $userId)->get());

        DB::transaction(function () use ($user, $userId, $telegramUserIds): void {
            $this->accounts->delete($user);
            $this->outbox->publish(Topics::USER_PURGE, ['user_id' => $userId, 'telegram_user_ids' => $telegramUserIds]);
        });

        DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $userId)->delete();
        $pseudonymized = AuditMaintenance::pseudonymizeUser($userId);

        $request->forceFill(['completed_at' => now()])->save();

        $this->auditor->record(
            'privacy.deletion_completed',
            metadata: ['subject_type' => 'user', 'audit_entries_pseudonymized' => $pseudonymized],
            actor: null,
            workspace: null,
            subjectLabel: AuditMaintenance::pseudonym($userId),
            platform: true,
            system: true,
        );

        return 'users';
    }

    /** @return 'workspaces' */
    private function purgeWorkspace(DeletionRequest $request): string
    {
        $workspace = Workspace::query()->find($request->subject_id);

        if ($workspace !== null) {
            $this->deleteExportFiles(DataExport::query()->where('workspace_id', $workspace->id)->get());
            $actor = $request->requested_by !== null ? User::query()->find($request->requested_by) : null;
            $this->workspaces->delete($workspace, $actor);
        }

        $request->forceFill(['completed_at' => now()])->save();

        $this->auditor->record(
            'privacy.workspace_purged',
            metadata: ['workspace_id' => $request->subject_id],
            actor: null,
            workspace: null,
            subjectLabel: $workspace?->name,
            platform: true,
            system: true,
        );

        return 'workspaces';
    }

    /** @param  iterable<DataExport>  $exports */
    private function deleteExportFiles(iterable $exports): void
    {
        foreach ($exports as $export) {
            if ($export->disk !== null && $export->path !== null) {
                Storage::disk($export->disk)->delete($export->path);
            }
        }
    }

    /** @return iterable<User> */
    private function owners(Workspace $workspace): iterable
    {
        return $workspace->members()->wherePivot('role', WorkspaceRole::Owner->value)->get();
    }
}
