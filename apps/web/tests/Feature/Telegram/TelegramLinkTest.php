<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Telegram\TelegramNotifier;

function issueTelegramToken(object $test): string
{
    $response = $test->postJson(route('settings.integrations.telegram.link'))->assertOk();
    $url = $response->json('url');

    expect($url)->toMatch('#^https://t\.me/test_bot\?start=[A-Za-z0-9]{40}$#');

    return substr($url, strpos($url, '=') + 1);
}

it('renders the integrations page', function (): void {
    $this->signInMember(WorkspaceRole::Member);

    $this->get(route('settings.integrations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Integrations', false)
            ->where('telegram', ['linked' => false, 'username' => null, 'linked_at' => null])
            ->where('botUsername', 'test_bot'));
});

it('issues a 15 minute single-use deep link and stores only a hash', function (): void {
    [$user] = $this->signInMember(WorkspaceRole::Member);

    $response = $this->postJson(route('settings.integrations.telegram.link'))->assertOk();
    $token = substr($response->json('url'), strpos($response->json('url'), '=') + 1);

    $row = TelegramLinkToken::query()->sole();
    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->token_hash)->not->toContain($token)
        ->and($row->user_id)->toBe($user->id)
        ->and((int) round(now()->diffInMinutes($row->expires_at)))->toBe(15)
        ->and($response->json('expires_at'))->toBe($row->expires_at->toIso8601String());

    $this->postJson(route('settings.integrations.telegram.link'))->assertOk();
    expect(TelegramLinkToken::query()->count())->toBe(1);
});

it('links the account through the signed internal endpoint and returns the context', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $token = issueTelegramToken($this);

    $this->internal('POST', '/internal/v1/telegram/link', [
        'token' => $token,
        'telegram_user_id' => 987654321,
        'chat_id' => 987654321,
        'username' => 'alex',
        'language_code' => 'ru',
    ])->assertOk()->assertJson([
        'linked' => true,
        'user' => ['id' => $user->id, 'name' => $user->name, 'locale' => 'en'],
        'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'plan' => 'free'],
        'role' => 'admin',
        'telegram' => ['user_id' => 987654321, 'chat_id' => 987654321, 'username' => 'alex'],
    ]);

    $account = TelegramAccount::query()->sole();
    expect($account->user_id)->toBe($user->id)
        ->and($account->workspace_id)->toBe($workspace->id)
        ->and(AuditLog::query()->where('action', 'telegram.linked')->where('workspace_id', $workspace->id)->exists())->toBeTrue();

    $this->get(route('settings.integrations'))
        ->assertInertia(fn ($page) => $page->where('telegram.linked', true)->where('telegram.username', 'alex'));
});

it('rejects reused, expired and unknown tokens', function (): void {
    $this->signInMember();
    $token = issueTelegramToken($this);
    $identity = ['telegram_user_id' => 1, 'chat_id' => 1];

    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $token, ...$identity])->assertOk();
    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $token, ...$identity])
        ->assertStatus(410)->assertJson(['error' => 'token_used']);

    $this->internal('POST', '/internal/v1/telegram/link', ['token' => str_repeat('x', 40), ...$identity])
        ->assertNotFound()->assertJson(['error' => 'invalid_token']);

    $expired = issueTelegramToken($this);
    $this->travel(16)->minutes();
    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $expired, ...$identity])
        ->assertStatus(410)->assertJson(['error' => 'token_expired']);
});

it('refuses to re-assign a Telegram identity that belongs to another user', function (): void {
    [$other] = $this->member();
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 42, 'chat_id' => 42, 'linked_at' => now()]);

    $this->signInMember();
    $token = issueTelegramToken($this);

    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $token, 'telegram_user_id' => 42, 'chat_id' => 42])
        ->assertStatus(409)->assertJson(['error' => 'already_linked']);

    expect(TelegramAccount::query()->where('telegram_user_id', 42)->value('user_id'))->toBe($other->id);
});

it('resolves the bot context by telegram id or user id', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Member);
    TelegramAccount::query()->create([
        'user_id' => $user->id, 'workspace_id' => $workspace->id, 'telegram_user_id' => 777, 'chat_id' => 778, 'linked_at' => now(),
    ]);

    $expected = [
        'user' => ['id' => $user->id],
        'workspace' => ['id' => $workspace->id],
        'role' => 'member',
        'permissions' => WorkspaceRole::Member->abilities(),
        'telegram' => ['user_id' => 777, 'chat_id' => 778],
    ];

    $this->internal('GET', '/internal/v1/telegram/context?telegram_user_id=777')->assertOk()->assertJson($expected);
    $this->internal('GET', '/internal/v1/telegram/context?user_id='.$user->id)->assertOk()->assertJson($expected);
    $this->internal('GET', '/internal/v1/telegram/context?telegram_user_id=1')->assertNotFound();
    $this->internal('GET', '/internal/v1/telegram/context')->assertStatus(422);
});

it('falls back to another workspace when the linked one is gone', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Member);
    [, $second] = $this->member(WorkspaceRole::Owner);
    $second->addMember($user, WorkspaceRole::Member);
    TelegramAccount::query()->create([
        'user_id' => $user->id, 'workspace_id' => $workspace->id, 'telegram_user_id' => 5, 'chat_id' => 5, 'linked_at' => now(),
    ]);
    $workspace->members()->detach($user->id);
    $user->forceFill(['current_workspace_id' => null])->save();

    $this->internal('GET', '/internal/v1/telegram/context?telegram_user_id=5')
        ->assertOk()
        ->assertJsonPath('workspace.id', $second->id);
});

it('unlinks and audits', function (): void {
    [$user] = $this->signInMember();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 9, 'chat_id' => 9, 'username' => 'bob', 'linked_at' => now()]);

    $this->delete(route('settings.integrations.telegram.unlink'))->assertSessionHas('success');

    expect(TelegramAccount::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'telegram.unlinked')->value('subject_label'))->toBe('@bob');
});

it('publishes localized telegram.notify messages only for linked users', function (): void {
    [$linked, $workspace] = $this->member(WorkspaceRole::Member, null, ['locale' => 'ru']);
    TelegramAccount::query()->create([
        'user_id' => $linked->id, 'workspace_id' => $workspace->id, 'telegram_user_id' => 11, 'chat_id' => 12, 'linked_at' => now(),
    ]);
    [$unlinked] = $this->member(WorkspaceRole::Member, $workspace);

    $notifier = app(TelegramNotifier::class);

    expect($notifier->notifyUser($unlinked->fresh(), 'notifications.telegram.unlinked'))->toBeNull();

    $message = $notifier->notifyUser(
        $linked->fresh(),
        'notifications.telegram.unlinked',
        buttons: [['text' => 'Open', 'url' => 'https://example.com/x']],
        reference: ['type' => 'test', 'id' => 5],
    );

    expect($message?->topic)->toBe('telegram.notify')
        ->and($message?->workspace_id)->toBe($workspace->id)
        ->and($message?->payload)->toEqual([
            'user_id' => $linked->id,
            'locale' => 'ru',
            'text_html' => 'Аккаунт Telegram отключён.',
            'buttons' => [['text' => 'Open', 'url' => 'https://example.com/x']],
            'reference' => ['type' => 'test', 'id' => '5'],
        ]);
});

it('validates telegram buttons', function (): void {
    app(TelegramNotifier::class)->notifyChat(1, 'hi', [['text' => 'No target']]);
})->throws(InvalidArgumentException::class);

it('escalates a refused question to workspace admins (telegram or email)', function (): void {
    config(['mail.default' => 'outbox']);
    [$asker, $workspace] = $this->member(WorkspaceRole::Member);
    TelegramAccount::query()->create(['user_id' => $asker->id, 'workspace_id' => $workspace->id, 'telegram_user_id' => 100, 'chat_id' => 100, 'linked_at' => now()]);

    [$admin] = $this->member(WorkspaceRole::Admin, $workspace, ['locale' => 'ru']);
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 200, 'chat_id' => 200, 'linked_at' => now()]);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);

    $this->internal('POST', '/internal/v1/telegram/actions', [
        'type' => 'escalate',
        'payload' => ['question' => 'Can I expense <script>?'],
        'telegram_user_id' => 100,
        'chat_id' => 100,
        'workspace_id' => $workspace->id,
        'locale' => 'en',
    ])->assertOk()->assertJson(['ok' => true, 'message' => __('notifications.telegram.escalated', [], 'en')]);

    $telegram = OutboxMessage::query()->topic('telegram.notify')->sole();
    expect($telegram->payload['user_id'])->toBe($admin->id)
        ->and($telegram->payload['text_html'])->toContain('&lt;script&gt;')
        ->and($telegram->payload['text_html'])->toContain('просит помощи');

    $email = OutboxMessage::query()->topic('email.send')->sole();
    expect($email->payload['to'])->toBe($owner->email)
        ->and($email->payload['reference']['type'])->toBe('escalation');

    expect(AuditLog::query()->where('action', 'assistant.escalated')->where('workspace_id', $workspace->id)->exists())->toBeTrue();
});

it('answers unknown actions with 404 and unlinked users with ok=false', function (): void {
    $this->internal('POST', '/internal/v1/telegram/actions', ['type' => 'nope', 'telegram_user_id' => 1, 'chat_id' => 1])
        ->assertNotFound()->assertJson(['ok' => false, 'error' => 'unknown_action']);

    $this->internal('POST', '/internal/v1/telegram/actions', ['type' => 'escalate', 'telegram_user_id' => 1, 'chat_id' => 1, 'locale' => 'ru'])
        ->assertOk()
        ->assertJson(['ok' => false, 'message' => __('notifications.telegram.not_linked', [], 'ru'), 'alert' => true]);
});

it('hides linking when no bot is configured', function (): void {
    config(['product.telegram.bot_username' => null]);
    $this->signInMember();

    $this->postJson(route('settings.integrations.telegram.link'))->assertStatus(422);
    expect(User::query()->count())->toBe(1);
});
