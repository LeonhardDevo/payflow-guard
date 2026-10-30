<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ApprovalDecision: string
{
    use HasValues;

    case Approved = 'approved';
    case Rejected = 'rejected';
}
