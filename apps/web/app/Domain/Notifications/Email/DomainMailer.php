<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Email;

use App\Models\OutboxMessage;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Carbon\CarbonInterface;

final readonly class DomainMailer
{
    public function __construct(private Outbox $outbox) {}

    public function publish(EmailSendV2 $email, ?CarbonInterface $availableAt = null): OutboxMessage
    {
        return $this->outbox->publish(Topics::EMAIL_SEND, $email->toPayload(), $email->workspaceId, $availableAt);
    }
}
