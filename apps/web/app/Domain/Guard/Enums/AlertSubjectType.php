<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum AlertSubjectType: string
{
    use HasValues;

    case VendorBankAccount = 'vendor_bank_account';
    case PayoutRequest = 'payout_request';
    case Invoice = 'invoice';
    case Client = 'client';
    case Vendor = 'vendor';
    case Workspace = 'workspace';
    case WorkspaceMember = 'workspace_member';
    case PaymentAccount = 'payment_account';
    case ReceivingDetailsVersion = 'receiving_details_version';
    case TrustedContact = 'trusted_contact';
}
