<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum BankChangeSource: string
{
    use HasValues;

    case Onboarding = 'onboarding';
    case VendorEmail = 'vendor_email';
    case VendorPhone = 'vendor_phone';
    case VendorPortal = 'vendor_portal';
    case InvoiceDocument = 'invoice_document';
    case ManualEntry = 'manual_entry';
    case Import = 'import';
}
