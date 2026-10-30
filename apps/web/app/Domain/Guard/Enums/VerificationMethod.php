<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum VerificationMethod: string
{
    use HasValues;

    case CallbackKnownPhone = 'callback_known_phone';
    case InPerson = 'in_person';
    case SignedLetter = 'signed_letter';
    case VideoCall = 'video_call';
    case VendorPortal2fa = 'vendor_portal_2fa';
}
