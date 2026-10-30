<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use App\Domain\Receivables\Models\InvoiceEvent;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;

final class TimelineResource
{
    /** @return array<string, mixed> */
    public static function event(InvoiceEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'actor' => ['type' => $event->actor_type->value, 'user' => UserRef::of($event->actor)],
            'data' => $event->data,
            'occurred_at' => Iso::dateTime($event->occurred_at),
        ];
    }
}
