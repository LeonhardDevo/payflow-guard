<?php

declare(strict_types=1);

namespace Tests\Feature\Payflow\Support;

use App\Billing\BillingException;
use App\Billing\Payments\PaymentGateway;
use App\Billing\Payments\PaymentLink;
use App\Billing\Payments\PaymentRequest;
use App\Billing\Payments\PaymentStatus;
use App\Models\PaymentAccount;
use Stripe\Exception\AuthenticationException;

final class RevokedKeyGateway implements PaymentGateway
{
    public static int $status = 401;

    public static function fromAccount(?PaymentAccount $account): static
    {
        return new self;
    }

    public function driver(): string
    {
        return 'fake';
    }

    public function currencies(): array
    {
        return ['USD', 'EUR', 'RUB'];
    }

    public function createPaymentLink(PaymentRequest $request): PaymentLink
    {
        throw $this->failure();
    }

    public function status(PaymentLink $link): PaymentStatus
    {
        throw $this->failure();
    }

    public function deactivate(PaymentLink $link): void
    {
        throw $this->failure();
    }

    private function failure(): BillingException
    {
        $previous = self::$status > 0 ? AuthenticationException::factory('Invalid API Key provided', self::$status) : null;

        return new BillingException('provider_unavailable', $previous);
    }
}
