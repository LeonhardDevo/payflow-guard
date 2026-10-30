<?php

declare(strict_types=1);

namespace App\Billing\YooKassa;

use App\Billing\BillingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class YooKassaClient
{
    public const int MAX_IDEMPOTENCE_KEY_LENGTH = 64;

    public function __construct(
        private readonly ?string $shopId = null,
        private readonly ?string $secretKey = null,
    ) {}

    public static function forShop(string $shopId, string $secretKey): self
    {
        return new self($shopId, $secretKey);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BillingException
     */
    public function cancelPayment(string $paymentId, string $idempotenceKey): array
    {
        self::assertPaymentId($paymentId);

        return $this->send('cancel_payment', fn (PendingRequest $http): Response => $http
            ->withHeaders(['Idempotence-Key' => $idempotenceKey])
            ->post('payments/'.$paymentId.'/cancel', (object) []));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws BillingException
     */
    public function createPayment(array $payload, string $idempotenceKey): array
    {
        if ($idempotenceKey === '' || strlen($idempotenceKey) > self::MAX_IDEMPOTENCE_KEY_LENGTH) {
            throw new \InvalidArgumentException('YooKassa Idempotence-Key must be 1-64 characters.');
        }

        return $this->send('create_payment', fn (PendingRequest $http): Response => $http
            ->withHeaders(['Idempotence-Key' => $idempotenceKey])
            ->post('payments', $payload));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BillingException
     */
    public function getPayment(string $paymentId): array
    {
        self::assertPaymentId($paymentId);

        return $this->send('get_payment', fn (PendingRequest $http): Response => $http->get('payments/'.$paymentId));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BillingException
     */
    public function me(): array
    {
        return $this->send('me', fn (PendingRequest $http): Response => $http->get('me'));
    }

    private static function assertPaymentId(string $paymentId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $paymentId) !== 1) {
            throw new BillingException('provider_unavailable');
        }
    }

    /** @return array{value: string, currency: string} */
    public static function amount(int $minorUnits, string $currency): array
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return [
            'value' => sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100),
            'currency' => strtoupper($currency),
        ];
    }

    public static function minorUnits(mixed $amount): ?int
    {
        $value = is_array($amount) ? ($amount['value'] ?? null) : null;

        if (! is_string($value) || preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(string $operation, callable $call): array
    {
        $shopId = (string) ($this->shopId ?? config('billing.yookassa.shop_id'));
        $secret = (string) ($this->secretKey ?? config('billing.yookassa.secret_key'));

        if ($shopId === '' || $secret === '') {
            throw new BillingException('gateway_unavailable');
        }

        $http = Http::baseUrl(rtrim((string) config('billing.yookassa.api_base'), '/').'/')
            ->withBasicAuth($shopId, $secret)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('billing.yookassa.timeout_seconds', 15))
            ->retry(2, 250, fn (\Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);

        try {
            $response = $call($http);
        } catch (ConnectionException $exception) {
            Log::error('billing.yookassa.'.$operation.'_failed', ['error' => $exception->getMessage()]);

            throw new BillingException('provider_unavailable', $exception);
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            Log::error('billing.yookassa.'.$operation.'_failed', [
                'status' => $response->status(),
                'code' => is_array($body) ? ($body['code'] ?? null) : null,
                'description' => is_array($body) ? ($body['description'] ?? null) : null,
                'parameter' => is_array($body) ? ($body['parameter'] ?? null) : null,
            ]);

            throw new BillingException('provider_unavailable');
        }

        return $body;
    }
}
