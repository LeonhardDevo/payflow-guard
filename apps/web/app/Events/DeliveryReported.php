<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;

final class DeliveryReported
{
    use Dispatchable;

    public function __construct(public readonly Delivery $delivery) {}
}
