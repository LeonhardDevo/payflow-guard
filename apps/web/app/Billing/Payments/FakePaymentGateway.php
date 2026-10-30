<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\BillingException;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Cache;

final class FakePaymentGateway implements PaymentGateway
{
    private const string CACHE_PREFIX = 'fake-payment:';

    private const string ID_PREFIX = 'plink_fake_';

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
        if (! in_array($request->money->currency, $this->currencies(), true)) {
            throw new BillingException('currency_unsupported');
        }

        $id = self::ID_PREFIX.substr(hash('sha256', $request->idempotencyKey()), 0, 24);
        Cache::add(self::CACHE_PREFIX.$id, PaymentStatus::Pending->value, now()->addDays(30));
        $separator = str_contains($request->returnUrl, '?') ? '&' : '?';

        return new PaymentLink('fake', $id, $request->returnUrl.$separator.'fake_payment='.$id, $request->money);
    }

    public function status(PaymentLink $link): PaymentStatus
    {
        $value = Cache::get(self::CACHE_PREFIX.$link->providerId);

        return is_string($value) ? (PaymentStatus::tryFrom($value) ?? PaymentStatus::Pending) : PaymentStatus::Canceled;
    }

    public function deactivate(PaymentLink $link): void
    {
        if ($this->status($link) === PaymentStatus::Pending) {
            self::settle($link->providerId, PaymentStatus::Canceled);
        }
    }

    public static function settle(string $providerId, PaymentStatus $status): void
    {
        if (! str_starts_with($providerId, self::ID_PREFIX)) {
            throw new BillingException('not_supported');
        }

        Cache::put(self::CACHE_PREFIX.$providerId, $status->value, now()->addDays(30));
    }
}
