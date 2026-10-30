<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum VendorCategory: string
{
    use HasValues;

    case Contractor = 'contractor';
    case Freelancer = 'freelancer';
    case Agency = 'agency';
    case Saas = 'saas';
    case Other = 'other';
}
