<?php

declare(strict_types=1);

namespace App\Notifications;

final class RecipientOptedOut extends InAppNotification
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $maskedEmail,
    ) {}

    public function inApp(object $notifiable): InAppMessage
    {
        return new InAppMessage(
            kind: 'warning',
            titleKey: 'notifications.in_app.recipient_opted_out.title',
            bodyKey: 'notifications.in_app.recipient_opted_out.body',
            bodyParams: ['email' => $this->maskedEmail],
            workspaceId: $this->workspaceId,
        );
    }
}
