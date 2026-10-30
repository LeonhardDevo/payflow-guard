<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum InvoiceSource: string
{
    use HasValues;

    case Manual = 'manual';
    case Import = 'import';
}
