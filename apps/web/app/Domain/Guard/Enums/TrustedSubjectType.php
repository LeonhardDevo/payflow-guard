<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum TrustedSubjectType: string
{
    use HasValues;

    case PayoutRequest = 'payout_request';
    case VendorBankAccount = 'vendor_bank_account';
    case ReceivingDetailsVersion = 'receiving_details_version';
}
