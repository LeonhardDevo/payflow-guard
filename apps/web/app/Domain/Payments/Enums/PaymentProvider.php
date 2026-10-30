<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use App\Domain\Shared\Enums\HasValues;

enum PaymentProvider: string
{
    use HasValues;

    case Manual = 'manual';
    case Fake = 'fake';
    case Stripe = 'stripe';
    case Yookassa = 'yookassa';
}
