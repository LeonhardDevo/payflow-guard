<?php

declare(strict_types=1);

namespace App\Mail;

use App\Outbox\OutboxMailTransport;
use App\Privacy\EmailSuppressions;
use Illuminate\Mail\Mailable;

abstract class AutomatedMailable extends Mailable
{
    abstract protected function senderWorkspaceId(): ?int;

    /** @return array<string, string> */
    protected function automatedHeaders(): array
    {
        $workspaceId = $this->senderWorkspaceId();

        return [
            OutboxMailTransport::HEADER_AUTOMATED => 'true',
            ...($workspaceId !== null ? [OutboxMailTransport::HEADER_WORKSPACE => (string) $workspaceId] : []),
        ];
    }

    protected function optOutUrl(string $email): string
    {
        return EmailSuppressions::url($email, $this->senderWorkspaceId());
    }
}
