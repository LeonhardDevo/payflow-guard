<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Collections\Models\Reminder;
use App\Models\User;

final class ReminderRecordPolicy
{
    use WorkspaceAbilities;

    public function view(User $user, Reminder $reminder): bool
    {
        return $this->allowed($user, $reminder, 'invoices.view');
    }

    public function send(User $user, Reminder $reminder): bool
    {
        return $this->allowed($user, $reminder, 'reminders.send');
    }
}
