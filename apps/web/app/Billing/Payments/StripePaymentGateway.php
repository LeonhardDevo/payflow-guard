<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\BillingException;
use App\Billing\Fake\FakeStripeEvents;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripePaymentGateway implements PaymentGateway
{
    public const array WEBHOOK_EVENTS = [
        'checkout.session.completed',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
    ];

    public function __construct(private StripeClient $stripe) {}

    public static function fromAccount(?PaymentAccount $account): static
    {
        $key = $account?->credential('restricted_key');

        if ($key === null) {
            throw new BillingException('payment_account_missing');
        }

        return new self(self::client($key));
    }

    public static function client(string $key): StripeClient
    {
        $base = config('billing.stripe.api_base');

        return new StripeClient(array_filter([
            'api_key' => $key,
            'stripe_version' => FakeStripeEvents::API_VERSION,
            'api_base' => is_string($base) && $base !== '' ? rtrim($base, '/') : null,
            'max_network_retries' => (int) config('billing.stripe.max_network_retries', 2),
        ], fn (mixed $value): bool => $value !== null));
    }

    public function driver(): string
    {
        return 'stripe';
    }

    public function currencies(): array
    {
        return ['USD', 'EUR'];
    }

    public function createPaymentLink(PaymentRequest $request): PaymentLink
    {
        if (! in_array($request->money->currency, $this->currencies(), true)) {
            throw new BillingException('currency_unsupported');
        }

        $metadata = $request->providerMetadata();
        $key = $request->idempotencyKey();

        $price = $this->call('price', fn () => $this->stripe->prices->create([
            'currency' => strtolower($request->money->currency),
            'unit_amount' => $request->money->amount,
            'product_data' => ['name' => Str::limit($request->description, 250, '')],
            'metadata' => $metadata,
        ], ['idempotency_key' => $key.'-price']));

        $link = $this->call('payment_link', fn () => $this->stripe->paymentLinks->create([
            'line_items' => [['price' => $price->id, 'quantity' => 1]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'after_completion' => ['type' => 'redirect', 'redirect' => ['url' => $request->returnUrl]],
            'restrictions' => ['completed_sessions' => ['limit' => 1]],
        ], ['idempotency_key' => $key.'-link']));

        if (! str_starts_with($link->url, 'https://')) {
            throw new BillingException('provider_unavailable');
        }

        return new PaymentLink('stripe', (string) $link->id, $link->url, $request->money);
    }

    public function status(PaymentLink $link): PaymentStatus
    {
        $sessions = $this->call('sessions', fn () => $this->stripe->checkout->sessions->all([
            'payment_link' => $link->providerId,
            'limit' => 10,
        ]));

        foreach ($sessions->data as $session) {
            if (($session->payment_status ?? null) === 'paid') {
                return PaymentStatus::Succeeded;
            }
        }

        $remote = $this->call('retrieve_link', fn () => $this->stripe->paymentLinks->retrieve($link->providerId));

        return ($remote->active ?? true) === false ? PaymentStatus::Canceled : PaymentStatus::Pending;
    }

    public function deactivate(PaymentLink $link): void
    {
        $this->call('deactivate', fn () => $this->stripe->paymentLinks->update(
            $link->providerId,
            ['active' => false],
            ['idempotency_key' => 'deactivate-'.$link->providerId],
        ));
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, secret: string}
     */
    public function registerWebhook(string $url, array $metadata): array
    {
        $endpoint = $this->call('webhook_endpoint', fn () => $this->stripe->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => self::WEBHOOK_EVENTS,
            'metadata' => $metadata,
            'description' => Str::limit((string) config('app.name').' payments', 120, ''),
        ], ['idempotency_key' => 'webhook-'.hash('sha256', $url)]));

        $secret = $endpoint->secret ?? null;

        if (! is_string($secret) || $secret === '') {
            throw new BillingException('provider_unavailable');
        }

        return ['id' => (string) $endpoint->id, 'secret' => $secret];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function call(string $operation, callable $request): mixed
    {
        try {
            return $request();
        } catch (ApiErrorException $exception) {
            Log::error('payments.stripe.'.$operation.'_failed', [
                'status' => $exception->getHttpStatus(),
                'code' => $exception->getStripeCode(),
                'error' => $exception->getMessage(),
            ]);

            throw new BillingException('provider_unavailable', $exception);
        }
    }
}
