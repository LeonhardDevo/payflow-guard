<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum InvoiceItemKind: string
{
    use HasValues;

    case Service = 'service';
    case LateFee = 'late_fee';
}
