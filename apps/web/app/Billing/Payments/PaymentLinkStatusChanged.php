<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Models\PaymentLinkRecord;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class PaymentLinkStatusChanged
{
    use Dispatchable;

    public function __construct(
        public PaymentLinkRecord $link,
        public PaymentStatus $previous,
        public string $source,
    ) {}
}
