<?php

declare(strict_types=1);

use App\Billing\StripeSignature;
use Stripe\WebhookSignature;

it('signs payloads with the Stripe t=..,v1=.. scheme', function (): void {
    expect(StripeSignature::sign('{"id":"evt_1"}', 'whsec_test_secret', 1700000000))
        ->toBe('t=1700000000,v1=248a374f50f943a28b0f6ab50faf9a7e7e29b710fa26df9fb1618b9bf8ea9c9a');
});

it('verifies its own signatures and rejects tampered or stale ones', function (): void {
    $header = StripeSignature::sign('{"id":"evt_1"}', 'whsec_test_secret', 1700000000);

    expect(StripeSignature::verify('{"id":"evt_1"}', $header, 'whsec_test_secret', 300, 1700000010))->toBeTrue()
        ->and(StripeSignature::verify('{"id":"evt_2"}', $header, 'whsec_test_secret', 300, 1700000010))->toBeFalse()
        ->and(StripeSignature::verify('{"id":"evt_1"}', $header, 'whsec_other', 300, 1700000010))->toBeFalse()
        ->and(StripeSignature::verify('{"id":"evt_1"}', $header, 'whsec_test_secret', 300, 1700000400))->toBeFalse();
});

it('is compatible with the official stripe-php verifier', function (): void {
    $payload = '{"id":"evt_compat","object":"event"}';
    $header = StripeSignature::sign($payload, 'whsec_test_secret');

    expect(WebhookSignature::verifyHeader($payload, $header, 'whsec_test_secret', 300))->toBeTrue();
});
