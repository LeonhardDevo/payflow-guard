<?php

declare(strict_types=1);

namespace App\Domain\Imports\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ImportPreset: string
{
    use HasValues;

    case Generic = 'generic';
    case Quickbooks = 'quickbooks';
    case Xero = 'xero';
    case Freshbooks = 'freshbooks';
}
