<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Locale\WithLocale;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Illuminate\Support\Str;

final readonly class TelegramNotifier
{
    public function __construct(private Outbox $outbox) {}

    /**
     * @param  array<string, mixed>  $replace
     * @param  list<array{text: string, url?: string, callback?: string}>  $buttons
     * @param  array{type: string, id: string|int}|null  $reference
     */
    public function notifyUser(
        User $user,
        string $translationKey,
        array $replace = [],
        array $buttons = [],
        ?array $reference = null,
        ?int $workspaceId = null,
    ): ?OutboxMessage {
        if ($user->telegramAccount === null) {
            return null;
        }

        $text = WithLocale::run($user->preferredLocale(), fn (): string => (string) __($translationKey, $replace));

        return $this->publish(
            ['user_id' => $user->id, 'locale' => $user->preferredLocale()],
            $text,
            $buttons,
            $reference,
            $workspaceId ?? $user->telegramAccount->workspace_id,
        );
    }

    /**
     * @param  list<array{text: string, url?: string, callback?: string}>  $buttons
     * @param  array{type: string, id: string|int}|null  $reference
     */
    public function notifyChat(
        int $chatId,
        string $textHtml,
        array $buttons = [],
        ?array $reference = null,
        ?int $workspaceId = null,
    ): OutboxMessage {
        return $this->publish(['chat_id' => $chatId], $textHtml, $buttons, $reference, $workspaceId);
    }

    /**
     * @param  array{user_id?: int, chat_id?: int, locale?: string}  $target
     * @param  list<array{text: string, url?: string, callback?: string}>  $buttons
     * @param  array{type: string, id: string|int}|null  $reference
     */
    private function publish(array $target, string $textHtml, array $buttons, ?array $reference, ?int $workspaceId): OutboxMessage
    {
        foreach ($buttons as $button) {
            if (trim($button['text']) === '' || (! isset($button['url']) && ! isset($button['callback']))) {
                throw new \InvalidArgumentException('Telegram buttons need text and url or callback.');
            }
        }

        return $this->outbox->publish(Topics::TELEGRAM_NOTIFY, [
            ...$target,
            'text_html' => $textHtml,
            'buttons' => $buttons,
            'reference' => $reference !== null
                ? ['type' => $reference['type'], 'id' => (string) $reference['id']]
                : ['type' => 'telegram', 'id' => (string) Str::uuid()],
        ], $workspaceId);
    }
}
