<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ConfirmationMode: string
{
    use HasValues;

    case SecondPerson = 'second_person';
    case TrustedContact = 'trusted_contact';
    case UnconfirmedSolo = 'unconfirmed_solo';
}
