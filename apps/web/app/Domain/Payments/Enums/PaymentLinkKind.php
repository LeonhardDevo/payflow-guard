<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use App\Domain\Shared\Enums\HasValues;

enum PaymentLinkKind: string
{
    use HasValues;

    case Reusable = 'reusable';
    case Single = 'single';
}
