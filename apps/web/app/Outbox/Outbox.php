<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Models\OutboxMessage;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

final class Outbox
{
    public const string CHANNEL = 'outbox';

    /** @param  array<string, mixed>  $payload */
    public function publish(
        string $topic,
        array $payload,
        ?int $workspaceId = null,
        ?CarbonInterface $availableAt = null,
    ): OutboxMessage {
        $message = new OutboxMessage;
        $message->topic = $topic;
        $message->payload = $payload;
        $message->workspace_id = $workspaceId;

        if ($availableAt !== null) {
            $message->available_at = $availableAt;
        }

        $message->save();

        $this->notifyAfterCommit($message->getConnection());

        return $message;
    }

    private function notifyAfterCommit(ConnectionInterface $connection): void
    {
        $notify = static fn (): bool => $connection->statement('NOTIFY '.self::CHANNEL);

        if ($connection instanceof Connection) {
            $connection->afterCommit($notify);

            return;
        }

        $notify();
    }
}
