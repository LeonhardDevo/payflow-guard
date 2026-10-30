<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ApprovalChannel: string
{
    use HasValues;

    case Web = 'web';
    case Telegram = 'telegram';
    case TrustedContact = 'trusted_contact';
}
