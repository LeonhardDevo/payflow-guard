<?php

declare(strict_types=1);

namespace App\Billing\Fake;

use App\Billing\BillingException;
use App\Billing\StripeSignature;
use App\Support\RequestId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

final readonly class FakeWebhookDispatcher
{
    public function __construct(
        private HttpFactory $http,
        private RequestId $requestId,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     *
     * @throws BillingException
     */
    public function dispatch(array $events): void
    {
        $secret = (string) (config('billing.stripe.webhook_secret')
            ?: (app()->isProduction() ? '' : config('billing.stripe.dev_webhook_secret')));

        if ($secret === '') {
            throw new BillingException('webhook_secret_missing');
        }

        $url = rtrim((string) config('internal.services.worker.url'), '/').'/webhooks/stripe';

        foreach ($events as $event) {
            $payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            try {
                $response = $this->http
                    ->withBody($payload, 'application/json')
                    ->withHeaders([
                        StripeSignature::HEADER => StripeSignature::sign($payload, $secret),
                        RequestId::HEADER => $this->requestId->get(),
                        'User-Agent' => 'Stripe/1.0 (+https://stripe.com/docs/webhooks) fake',
                    ])
                    ->timeout(10)
                    ->post($url);
            } catch (ConnectionException $exception) {
                Log::warning('billing.fake.webhook_unreachable', ['url' => $url, 'error' => $exception->getMessage()]);

                throw new BillingException('worker_unavailable', $exception);
            }

            if (! $response->successful()) {
                Log::warning('billing.fake.webhook_rejected', [
                    'url' => $url,
                    'status' => $response->status(),
                    'event_type' => $event['type'] ?? null,
                ]);

                throw new BillingException('worker_rejected');
            }
        }
    }
}
