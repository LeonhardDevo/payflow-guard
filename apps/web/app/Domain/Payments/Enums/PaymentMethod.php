<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use App\Domain\Shared\Enums\HasValues;

enum PaymentMethod: string
{
    use HasValues;

    case PaymentLink = 'payment_link';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Cash = 'cash';
    case Other = 'other';
}
