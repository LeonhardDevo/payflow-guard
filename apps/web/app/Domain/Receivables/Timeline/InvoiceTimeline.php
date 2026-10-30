<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Timeline;

use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Models\InvoiceEvent;
use App\Domain\Shared\Time\Clock;
use App\Models\User;
use Carbon\CarbonInterface;

final readonly class InvoiceTimeline
{
    public function __construct(private Clock $clock) {}

    /** @param  array<string, string|int|bool|null>  $data */
    public function record(
        Invoice $invoice,
        InvoiceEventType $type,
        array $data = [],
        ?User $actor = null,
        ?EventActorType $actorType = null,
        ?CarbonInterface $at = null,
    ): InvoiceEvent {
        $event = new InvoiceEvent;
        $event->forceFill([
            'workspace_id' => $invoice->workspace_id,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'type' => $type,
            'actor_type' => $actorType ?? ($actor !== null ? EventActorType::User : EventActorType::System),
            'actor_id' => $actor?->id,
            'data' => $data,
            'occurred_at' => $at ?? $this->clock->now(),
        ])->save();

        return $event;
    }
}
