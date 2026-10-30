<?php

declare(strict_types=1);

namespace App\Domain\Imports\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ImportKind: string
{
    use HasValues;

    case Clients = 'clients';
    case Invoices = 'invoices';
}
