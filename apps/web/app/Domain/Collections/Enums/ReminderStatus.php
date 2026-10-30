<?php

declare(strict_types=1);

namespace App\Domain\Collections\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ReminderStatus: string
{
    use HasValues;

    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Deferred = 'deferred';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Canceled = 'canceled';
}
