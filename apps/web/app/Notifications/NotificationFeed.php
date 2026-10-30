<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationFeed
{
    public const int LIMIT = 10;

    /** @return array{unread: int, items: list<array{id: string, title: string, body: string|null, kind: string, href: string|null, read: bool, created_at: string|null}>} */
    public function for(User $user, ?Workspace $workspace): array
    {
        $items = array_values($this->query($user, $workspace)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->present($notification))
            ->all());

        return [
            'unread' => $this->query($user, $workspace)->whereNull('read_at')->count(),
            'items' => $items,
        ];
    }

    public function markRead(User $user, ?Workspace $workspace, ?string $id = null): int
    {
        return $this->query($user, $workspace)
            ->whereNull('read_at')
            ->when($id !== null, fn (Builder $query) => $query->whereKey($id))
            ->update(['read_at' => now()]);
    }

    /** @return Builder<DatabaseNotification> */
    private function query(User $user, ?Workspace $workspace): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where(function (Builder $scope) use ($workspace): void {
                $scope->whereNull('data->workspace_id');

                if ($workspace !== null) {
                    $scope->orWhere('data->workspace_id', $workspace->id);
                }
            });
    }

    /** @return array{id: string, title: string, body: string|null, kind: string, href: string|null, read: bool, created_at: string|null} */
    private function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $kind = $data['kind'] ?? null;
        $href = $data['href'] ?? null;

        return [
            'id' => (string) $notification->id,
            'title' => $this->translate($data['title'] ?? null) ?? '',
            'body' => $this->translate($data['body'] ?? null),
            'kind' => is_string($kind) && in_array($kind, InAppMessage::KINDS, true) ? $kind : 'info',
            'href' => is_string($href) && str_starts_with($href, '/') && ! str_starts_with($href, '//') ? $href : null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function translate(mixed $entry): ?string
    {
        if (! is_array($entry) || ! is_string($entry['key'] ?? null)) {
            return null;
        }

        $params = [];

        foreach (is_array($entry['params'] ?? null) ? $entry['params'] : [] as $name => $value) {
            $params[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        $text = __($entry['key'], $params);

        return is_string($text) ? $text : null;
    }
}
