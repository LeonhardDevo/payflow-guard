<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Audit\Auditor;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TelegramLinker
{
    public function __construct(private Auditor $auditor) {}

    /** @return array{url: string, token: string, expires_at: CarbonInterface} */
    public function issueLink(User $user, ?Workspace $workspace): array
    {
        $botUsername = config('product.telegram.bot_username');

        if (! is_string($botUsername) || $botUsername === '') {
            throw new \RuntimeException('Telegram bot username is not configured.');
        }

        $token = Str::random(40);
        $expiresAt = now()->addMinutes((int) config('product.telegram.link_ttl_minutes', 15));

        DB::transaction(function () use ($user, $workspace, $token, $expiresAt): void {
            TelegramLinkToken::query()->where('user_id', $user->id)->whereNull('used_at')->delete();

            TelegramLinkToken::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace?->id,
                'token_hash' => TelegramLinkToken::hashToken($token),
                'expires_at' => $expiresAt,
            ]);
        });

        return [
            'url' => 'https://t.me/'.ltrim($botUsername, '@').'?start='.$token,
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param  array{telegram_user_id: int, chat_id: int, username?: string|null, language_code?: string|null}  $identity
     *
     * @throws TelegramLinkException
     */
    public function link(string $token, array $identity): TelegramAccount
    {
        return DB::transaction(function () use ($token, $identity): TelegramAccount {
            $linkToken = TelegramLinkToken::query()
                ->where('token_hash', TelegramLinkToken::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($linkToken === null) {
                throw new TelegramLinkException('invalid_token');
            }

            if (! $linkToken->isUsable()) {
                throw new TelegramLinkException($linkToken->used_at !== null ? 'token_used' : 'token_expired');
            }

            $user = $linkToken->user;

            $linkedElsewhere = TelegramAccount::query()
                ->where('telegram_user_id', $identity['telegram_user_id'])
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($linkedElsewhere) {
                throw new TelegramLinkException('already_linked');
            }

            $linkToken->forceFill(['used_at' => now()])->save();

            $workspaceId = $linkToken->workspace_id !== null && $user->belongsToWorkspace($linkToken->workspace_id)
                ? $linkToken->workspace_id
                : $user->current_workspace_id;

            $account = TelegramAccount::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'workspace_id' => $workspaceId,
                    'telegram_user_id' => $identity['telegram_user_id'],
                    'chat_id' => $identity['chat_id'],
                    'username' => $identity['username'] ?? null,
                    'language_code' => $identity['language_code'] ?? null,
                    'linked_at' => now(),
                ],
            );

            $this->auditor->record(
                'telegram.linked',
                $account,
                metadata: ['username' => $account->username],
                actor: $user,
                workspace: $workspaceId,
                subjectLabel: $account->username !== null ? '@'.$account->username : (string) $account->telegram_user_id,
            );

            return $account;
        });
    }

    public function unlink(User $user): bool
    {
        $account = $user->telegramAccount;

        if ($account === null) {
            return false;
        }

        $this->auditor->record(
            'telegram.unlinked',
            $account,
            metadata: ['username' => $account->username],
            actor: $user,
            subjectLabel: $account->username !== null ? '@'.$account->username : (string) $account->telegram_user_id,
        );

        $account->delete();
        $user->unsetRelation('telegramAccount');

        return true;
    }
}
