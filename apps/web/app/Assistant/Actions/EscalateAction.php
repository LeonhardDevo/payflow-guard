<?php

declare(strict_types=1);

namespace App\Assistant\Actions;

use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Notifications\EscalationRequested;
use App\Telegram\TelegramNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EscalateAction implements AssistantAction
{
    public function __construct(
        private TelegramNotifier $telegram,
        private Auditor $auditor,
    ) {}

    public function handle(AssistantActionContext $context): AssistantActionResult
    {
        if ($context->user === null || $context->workspace === null) {
            return AssistantActionResult::failure((string) __('notifications.telegram.not_linked'));
        }

        $question = Str::limit(trim((string) ($context->payload['question'] ?? $context->payload['arg'] ?? '')), 500);
        $user = $context->user;
        $workspace = $context->workspace;

        $recipients = $workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->where('users.id', '!=', $user->id)
            ->with('telegramAccount')
            ->get();

        if ($recipients->isEmpty()) {
            return AssistantActionResult::failure((string) __('notifications.telegram.escalation_no_admins'));
        }

        DB::transaction(function () use ($recipients, $user, $workspace, $question, $context): void {
            foreach ($recipients as $recipient) {
                /** @var User $recipient */
                $replace = ['name' => e($user->name), 'workspace' => e($workspace->name), 'question' => e($question)];

                $sent = $this->telegram->notifyUser(
                    $recipient,
                    'notifications.telegram.escalation_html',
                    $replace,
                    reference: ['type' => 'escalation', 'id' => $user->id.'-'.now()->timestamp],
                    workspaceId: $workspace->id,
                );

                $recipient->notify(new EscalationRequested($user, $workspace, $question, viaMail: $sent === null));
            }

            $this->auditor->record(
                'assistant.escalated',
                $user,
                metadata: ['channel' => $context->channel->value, 'recipients' => $recipients->count()],
                actor: $user,
                workspace: $workspace,
                subjectLabel: Str::limit($question, 120) ?: $user->name,
            );
        });

        return AssistantActionResult::success((string) __('notifications.telegram.escalated'));
    }
}
