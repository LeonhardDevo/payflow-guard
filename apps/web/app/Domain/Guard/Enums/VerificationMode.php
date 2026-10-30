<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum VerificationMode: string
{
    use HasValues;

    case Standard = 'standard';
    case Solo = 'solo';
}
