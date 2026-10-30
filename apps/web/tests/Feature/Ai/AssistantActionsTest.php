<?php

declare(strict_types=1);

use App\Assistant\Actions\AssistantAction;
use App\Assistant\Actions\AssistantActionContext;
use App\Assistant\Actions\AssistantActionResult;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Notifications\EscalationRequested;
use Illuminate\Notifications\DatabaseNotification;

it('escalates from the web assistant to owners and admins (in-app + telegram or email)', function (): void {
    config(['mail.default' => 'outbox']);
    [$asker, $workspace] = $this->signInMember(WorkspaceRole::Member);
    [$admin] = $this->member(WorkspaceRole::Admin, $workspace);
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 200, 'chat_id' => 200, 'linked_at' => now()]);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace, ['locale' => 'ru']);

    $this->postJson(route('assistant.actions'), [
        'type' => 'escalate',
        'payload' => ['reason' => 'not_found', 'question' => 'Can I expense a taxi?', 'conversation_id' => 'web-1'],
    ])->assertOk()->assertExactJson(['ok' => true, 'message' => __('notifications.telegram.escalated')]);

    expect(OutboxMessage::query()->topic('telegram.notify')->sole()->payload['user_id'])->toBe($admin->id)
        ->and(OutboxMessage::query()->topic('email.send')->sole()->payload['to'])->toBe($owner->email);

    $inApp = DatabaseNotification::query()->where('type', EscalationRequested::class)->get();
    expect($inApp)->toHaveCount(2)
        ->and($inApp->pluck('notifiable_id')->sort()->values()->all())->toBe(collect([$admin->id, $owner->id])->sort()->values()->all())
        ->and($inApp->first()->data['workspace_id'])->toBe($workspace->id)
        ->and($inApp->first()->data['href'])->toBe('/assistant/history?status=refused');

    $audit = AuditLog::query()->where('action', 'assistant.escalated')->sole();
    expect($audit->actor_id)->toBe($asker->id)
        ->and($audit->metadata['channel'])->toBe('web');
});

it('answers 422 with a message when nobody can take the escalation', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->postJson(route('assistant.actions'), ['type' => 'escalate', 'payload' => ['question' => 'Hello?']])
        ->assertStatus(422)
        ->assertExactJson(['ok' => false, 'message' => __('notifications.telegram.escalation_no_admins')]);
});

it('answers 404 for unknown action types and validates the type', function (): void {
    $this->signInMember(WorkspaceRole::Member);

    $this->postJson(route('assistant.actions'), ['type' => 'invoice.approve'])
        ->assertNotFound()
        ->assertJson(['ok' => false, 'error' => 'unknown_action', 'message' => __('notifications.assistant.action_unknown')]);

    $this->postJson(route('assistant.actions'), ['type' => 'bad type!'])->assertUnprocessable();
});

it('runs product actions registered in config with the web channel context', function (): void {
    $probe = new class implements AssistantAction
    {
        public ?AssistantActionContext $seen = null;

        public function handle(AssistantActionContext $context): AssistantActionResult
        {
            $this->seen = $context;

            return AssistantActionResult::success('Done', '<b>ignored on web</b>');
        }
    };
    app()->instance($probe::class, $probe);
    config(['product.assistant.actions.probe' => $probe::class]);
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Member);

    $this->postJson(route('assistant.actions'), ['type' => 'probe', 'payload' => ['arg' => '42', 'nested' => ['x' => 1]]])
        ->assertOk()
        ->assertExactJson(['ok' => true, 'message' => 'Done']);

    expect($probe->seen?->channel->value)->toBe('web')
        ->and($probe->seen?->user?->id)->toBe($user->id)
        ->and($probe->seen?->workspace?->id)->toBe($workspace->id)
        ->and($probe->seen?->role)->toBe(WorkspaceRole::Member)
        ->and($probe->seen?->telegramUserId)->toBeNull()
        ->and($probe->seen?->payload)->toBe(['arg' => '42']);
});

it('requires a signed-in workspace member', function (): void {
    $this->postJson(route('assistant.actions'), ['type' => 'escalate'])->assertUnauthorized();
});
