<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\BillingException;
use App\Billing\YooKassa\YooKassaClient;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

final readonly class YooKassaPaymentGateway implements PaymentGateway
{
    public const string KIND = 'client_payment';

    public function __construct(
        private YooKassaClient $client,
        private bool $receipts = false,
    ) {}

    public static function fromAccount(?PaymentAccount $account): static
    {
        $shopId = $account?->credential('shop_id');
        $secret = $account?->credential('secret_key');

        if ($shopId === null || $secret === null) {
            throw new BillingException('payment_account_missing');
        }

        return new self(YooKassaClient::forShop($shopId, $secret), $account->credential('receipts') === '1');
    }

    public function driver(): string
    {
        return 'yookassa';
    }

    public function currencies(): array
    {
        return ['RUB'];
    }

    public function createPaymentLink(PaymentRequest $request): PaymentLink
    {
        if (! in_array($request->money->currency, $this->currencies(), true)) {
            throw new BillingException('currency_unsupported');
        }

        $payload = [
            'amount' => YooKassaClient::amount($request->money->amount, $request->money->currency),
            'capture' => true,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $request->returnUrl,
                'locale' => App::getLocale() === 'ru' ? 'ru_RU' : 'en_US',
            ],
            'description' => Str::limit($request->description, 128, ''),
            'metadata' => ['kind' => self::KIND, ...$request->providerMetadata()],
        ];

        if ($this->receipts) {
            if ($request->customerEmail === null || $request->customerEmail === '') {
                throw new BillingException('receipt_email_missing');
            }

            $payload['receipt'] = [
                'customer' => ['email' => $request->customerEmail],
                'items' => [[
                    'description' => Str::limit($request->description, 128, ''),
                    'quantity' => '1.00',
                    'amount' => YooKassaClient::amount($request->money->amount, $request->money->currency),
                    'vat_code' => (int) config('billing.yookassa.vat_code', 1),
                    'payment_subject' => 'service',
                    'payment_mode' => 'full_payment',
                ]],
            ];
        }

        $payment = $this->client->createPayment($payload, self::key($request->idempotencyKey()));
        $confirmation = $payment['confirmation'] ?? null;
        $url = is_array($confirmation) ? ($confirmation['confirmation_url'] ?? null) : null;
        $id = $payment['id'] ?? null;

        if (! is_string($id) || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new BillingException('provider_unavailable');
        }

        return new PaymentLink('yookassa', $id, $url, $request->money);
    }

    public function status(PaymentLink $link): PaymentStatus
    {
        return self::map($this->client->getPayment($link->providerId));
    }

    public function deactivate(PaymentLink $link): void
    {
        $payment = $this->client->getPayment($link->providerId);

        if (($payment['status'] ?? null) === 'waiting_for_capture') {
            $this->client->cancelPayment($link->providerId, 'cancel-'.$link->providerId);
        }
    }

    /** @param  array<string, mixed>  $payment */
    public static function map(array $payment): PaymentStatus
    {
        $status = $payment['status'] ?? null;

        if ($status === 'succeeded') {
            $refunded = YooKassaClient::minorUnits($payment['refunded_amount'] ?? null) ?? 0;
            $amount = YooKassaClient::minorUnits($payment['amount'] ?? null) ?? PHP_INT_MAX;

            return $refunded > 0 && $refunded >= $amount ? PaymentStatus::Refunded : PaymentStatus::Succeeded;
        }

        return $status === 'canceled' ? PaymentStatus::Canceled : PaymentStatus::Pending;
    }

    private static function key(string $key): string
    {
        return strlen($key) <= YooKassaClient::MAX_IDEMPOTENCE_KEY_LENGTH ? $key : 'payment-'.substr(hash('sha256', $key), 0, 48);
    }
}
