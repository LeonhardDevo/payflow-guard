<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum PayoutHoldReason: string
{
    use HasValues;

    case BankDetailsChanged = 'bank_details_changed';
    case BankDetailsUnverified = 'bank_details_unverified';
    case VendorOnHold = 'vendor_on_hold';
    case ApproverRemoved = 'approver_removed';
}
