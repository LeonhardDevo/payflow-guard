<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

abstract class InAppNotification extends Notification
{
    abstract public function inApp(object $notifiable): InAppMessage;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    final public function toDatabase(object $notifiable): array
    {
        return $this->inApp($notifiable)->toArray();
    }
}
