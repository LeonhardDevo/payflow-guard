<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Internal\InternalKeyring;
use App\Providers\AppServiceProvider;

function bootGuard(): void
{
    app()->forgetInstance(InternalKeyring::class);
    (new AppServiceProvider(app()))->guardProductionConfiguration();
}

afterEach(function (): void {
    app()['env'] = 'testing';
    app()->forgetInstance(InternalKeyring::class);
});

it('refuses to boot production with the fake billing driver', function (): void {
    config(['internal.keys' => ['worker' => str_repeat('w', 32), 'ai' => str_repeat('a', 32)]]);
    config(['billing.driver' => 'fake', 'billing.allow_fake_in_production' => false]);
    app()['env'] = 'production';

    expect(fn () => bootGuard())->toThrow(RuntimeException::class, 'BILLING_DRIVER=fake');

    config(['billing.allow_fake_in_production' => true]);
    expect(fn () => bootGuard())->not->toThrow(RuntimeException::class);

    config(['billing.driver' => 'stripe', 'billing.allow_fake_in_production' => false]);
    expect(fn () => bootGuard())->not->toThrow(RuntimeException::class);
});

it('refuses to boot production without internal pair keys', function (): void {
    config(['billing.driver' => 'stripe', 'internal.keys' => ['worker' => null, 'ai' => null]]);
    app()['env'] = 'production';

    expect(fn () => bootGuard())->toThrow(RuntimeException::class, 'pair key for [worker]');
});

it('does not guard non-production environments', function (): void {
    config(['billing.driver' => 'fake', 'internal.keys' => ['worker' => null, 'ai' => null]]);

    expect(fn () => bootGuard())->not->toThrow(RuntimeException::class);
});

it('hides the fake checkout in production unless explicitly allowed', function (): void {
    $this->signInMember(WorkspaceRole::Owner);
    app()['env'] = 'production';

    $this->get('/dev/fake-checkout/cs_test_'.str_repeat('a', 32))->assertNotFound();
});

it('refuses to boot production with the yookassa driver but no shop credentials', function (): void {
    config(['internal.keys' => ['worker' => str_repeat('w', 32), 'ai' => str_repeat('a', 32)]]);
    config(['billing.driver' => 'yookassa', 'billing.yookassa.shop_id' => '100500', 'billing.yookassa.secret_key' => null]);
    app()['env'] = 'production';

    expect(fn () => bootGuard())->toThrow(RuntimeException::class, 'BILLING_DRIVER=yookassa');

    config(['billing.yookassa.secret_key' => 'live_secret']);
    expect(fn () => bootGuard())->not->toThrow(RuntimeException::class);
});
