<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

final class InvalidInvoiceTransition extends PayflowException
{
    public function __construct()
    {
        parent::__construct('invalid_transition', 409);
    }
}
