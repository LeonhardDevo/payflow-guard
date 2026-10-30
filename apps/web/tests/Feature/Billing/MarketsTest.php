<?php

declare(strict_types=1);

use App\Billing\BillingException;
use App\Billing\BillingGateways;
use App\Billing\Fake\FakeGateway;
use App\Billing\Markets\MarketResolver;
use App\Billing\StripeGateway;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

it('resolves currency and gateway from the market', function (string $country, ?string $currency, string $expectedCurrency, string $expectedGateway): void {
    config(['billing.driver' => 'market']);

    $market = app(MarketResolver::class)->forCountry($country, $currency);

    expect($market->currency)->toBe($expectedCurrency)
        ->and($market->gateway)->toBe($expectedGateway)
        ->and($market->country)->toBe($country);
})->with([
    'United States' => ['US', 'USD', 'USD', 'stripe'],
    'Germany defaults to EUR' => ['DE', null, 'EUR', 'stripe'],
    'Germany keeps USD when chosen' => ['DE', 'USD', 'USD', 'stripe'],
    'Kazakhstan falls back to USD' => ['KZ', 'KZT', 'USD', 'stripe'],
    'Russia uses YooKassa and RUB' => ['RU', 'USD', 'RUB', 'yookassa'],
    'RUB elsewhere uses YooKassa' => ['AM', 'RUB', 'RUB', 'yookassa'],
]);

it('lets configuration force a gateway or change the market mapping', function (): void {
    config(['billing.driver' => 'stripe']);
    expect(app(MarketResolver::class)->forCountry('RU', 'RUB')->toContract())
        ->toBe(['country' => 'RU', 'currency' => 'USD', 'gateway' => 'stripe']);

    config(['billing.driver' => 'fake']);
    expect(app(MarketResolver::class)->forCountry('RU', 'RUB')->toContract())
        ->toBe(['country' => 'RU', 'currency' => 'RUB', 'gateway' => 'fake']);

    config(['billing.driver' => 'market', 'billing.markets.country_gateways' => [], 'billing.markets.currency_gateways' => []]);
    expect(app(MarketResolver::class)->forCountry('RU', 'RUB')->gateway)->toBe('stripe');
});

it('picks the visitor market from ?currency, the workspace or the locale', function (): void {
    config(['billing.driver' => 'market']);
    $markets = app(MarketResolver::class);

    expect($markets->forRequest(Request::create('/pricing?currency=eur'))->currency)->toBe('EUR')
        ->and($markets->forRequest(Request::create('/pricing?currency=GBP'))->currency)->toBe('USD')
        ->and($markets->forRequest(Request::create('/pricing'), Workspace::factory()->make(['country' => 'FR', 'currency' => 'EUR']))->currency)->toBe('EUR');

    app()->setLocale('ru');
    expect($markets->forRequest(Request::create('/pricing'))->toContract())
        ->toBe(['country' => 'US', 'currency' => 'USD', 'gateway' => 'stripe'])
        ->and($markets->billingCurrencies())->toBe(['USD', 'EUR'])
        ->and($markets->forRequest(Request::create('/pricing?currency=RUB'))->currency)->toBe('USD');

    config(['billing.yookassa.shop_id' => '100500', 'billing.yookassa.secret_key' => 'test_secret']);
    expect($markets->forRequest(Request::create('/pricing'))->toContract())
        ->toBe(['country' => 'RU', 'currency' => 'RUB', 'gateway' => 'yookassa'])
        ->and($markets->billingCurrencies())->toBe(['USD', 'EUR', 'RUB']);
});

it('resolves the gateway driver per workspace and reports a missing one', function (): void {
    config(['billing.driver' => 'market']);
    $gateways = app(BillingGateways::class);

    expect($gateways->forWorkspace(Workspace::factory()->make(['country' => 'US', 'currency' => 'USD'])))->toBeInstanceOf(StripeGateway::class);

    config(['billing.gateways.yookassa' => 'App\\Billing\\YooKassa\\MissingGateway']);
    expect(fn () => $gateways->forWorkspace(Workspace::factory()->make(['country' => 'RU', 'currency' => 'RUB'])))
        ->toThrow(BillingException::class, 'gateway_unavailable');

    config(['billing.driver' => 'fake']);
    expect($gateways->forWorkspace(Workspace::factory()->make(['country' => 'RU', 'currency' => 'RUB'])))->toBeInstanceOf(FakeGateway::class);
});

it('asks for the country at onboarding and stores the market', function (): void {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->get(route('onboarding.workspace'))
        ->assertInertia(fn ($page) => $page
            ->where('defaultCountry', 'US')
            ->has('countries', count(config('billing.markets.countries')))
            ->where('countries', fn ($countries): bool => collect($countries)->contains(
                fn (array $country): bool => $country['code'] === 'RU' && $country['currency'] === 'RUB' && $country['gateway'] === 'yookassa' && $country['available'] === true,
            )));

    $this->post(route('onboarding.workspace.store'), [
        'name' => 'Acme GmbH',
        'timezone' => 'Europe/Berlin',
        'currency' => 'EUR',
        'country' => 'de',
        'dpa' => '1',
    ])->assertRedirect(route('dashboard'));

    $workspace = Workspace::query()->where('name', 'Acme GmbH')->sole();
    expect($workspace->country)->toBe('DE')
        ->and($workspace->currency)->toBe('EUR');

    $this->post(route('onboarding.workspace.store'), [
        'name' => 'Bad Country',
        'timezone' => 'UTC',
        'currency' => 'USD',
        'country' => 'ZZ',
        'dpa' => '1',
    ])->assertSessionHasErrors('country');

    $this->post(route('onboarding.workspace.store'), [
        'name' => 'No Country',
        'timezone' => 'UTC',
        'currency' => 'USD',
        'dpa' => '1',
    ])->assertSessionHasNoErrors();
    expect(Workspace::query()->where('name', 'No Country')->value('country'))->toBe('US');
});

it('shows plans in the market currency on the pricing and billing pages', function (): void {
    $this->get(route('pricing', ['currency' => 'EUR']))
        ->assertInertia(fn ($page) => $page
            ->where('market.currency', 'EUR')
            ->where('plans.1.currency', 'EUR')
            ->where('plans.1.price_monthly', 17)
            ->where('currencies', ['USD', 'EUR', 'RUB']));

    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['country' => 'RU', 'currency' => 'RUB'])->save();

    $this->get(route('settings.billing'))
        ->assertInertia(fn ($page) => $page
            ->where('market.currency', 'RUB')
            ->where('market.gateway', 'fake')
            ->where('plans.2.currency', 'RUB')
            ->where('plans.2.price_monthly', 3990)
            ->where('capabilities.manage', true)
            ->where('capabilities.portal', false));
});

it('flashes an error when the market gateway is not connected', function (): void {
    config(['billing.driver' => 'market', 'billing.gateways.yookassa' => 'App\\Billing\\YooKassa\\MissingGateway']);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['country' => 'RU', 'currency' => 'RUB'])->save();

    $this->get(route('settings.billing'))->assertInertia(fn ($page) => $page->where('market.available', false));

    $this->post(route('settings.billing.checkout'), ['plan' => 'pro', 'interval' => 'month'])
        ->assertSessionHas('error', __('billing.errors.gateway_unavailable'));
});
