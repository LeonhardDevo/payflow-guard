<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum InvoiceStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Disputed = 'disputed';
    case Uncollectible = 'uncollectible';
    case Void = 'void';
}
