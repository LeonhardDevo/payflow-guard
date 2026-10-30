<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\BillingException;
use App\Models\PaymentAccount;

interface PaymentGateway
{
    /** @throws BillingException */
    public static function fromAccount(?PaymentAccount $account): static;

    public function driver(): string;

    /** @return list<string> */
    public function currencies(): array;

    /** @throws BillingException */
    public function createPaymentLink(PaymentRequest $request): PaymentLink;

    /** @throws BillingException */
    public function status(PaymentLink $link): PaymentStatus;

    /** @throws BillingException */
    public function deactivate(PaymentLink $link): void;
}
