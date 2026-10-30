<?php

declare(strict_types=1);

use App\Events\DeliveryReported;
use App\Models\Delivery;
use Illuminate\Support\Facades\Event;

it('records delivery reports and dispatches DeliveryReported', function (): void {
    Event::fake([DeliveryReported::class]);

    $this->internal('POST', '/internal/v1/deliveries', [
        'reference' => ['type' => 'invitation', 'id' => 42],
        'channel' => 'email',
        'status' => 'sent',
        'provider_message_id' => '<abc@mailpit>',
    ])->assertCreated();

    $delivery = Delivery::query()->sole();

    expect($delivery->reference_type)->toBe('invitation')
        ->and($delivery->reference_id)->toBe('42')
        ->and($delivery->status)->toBe('sent')
        ->and($delivery->provider_message_id)->toBe('<abc@mailpit>');

    Event::assertDispatched(DeliveryReported::class, fn (DeliveryReported $event): bool => $event->delivery->is($delivery));
});
