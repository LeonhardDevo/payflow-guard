<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\Markets\MarketResolver;
use App\Domain\Payments\Accounts\PaymentAccountManager;
use App\Domain\Payments\Links\PaymentAccountState;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use App\Models\PaymentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PaymentSettingsController extends PayflowController
{
    public function show(PaymentAccountState $state, PaymentAccountManager $manager, MarketResolver $markets): Response
    {
        $workspace = $this->workspace();
        $account = $state->live($workspace);
        $market = strtoupper((string) $workspace->country) === 'RU' || strtoupper($workspace->currency) === 'RUB' ? 'ru' : 'intl';
        $drivers = $market === 'ru' ? ['yookassa'] : ['stripe'];

        if (config('payflow.allow_fake_payments') === true) {
            $drivers[] = 'fake';
        }

        return Inertia::render('settings/Payments', [
            'account' => $account !== null ? $this->account($account, $state, $manager) : null,
            'market' => $market,
            'available_drivers' => $drivers,
            'demo_allowed' => config('payflow.allow_fake_payments') === true,
            'stripe_required_permissions' => PaymentAccountManager::STRIPE_PERMISSIONS,
            'yookassa_webhook_url' => rtrim((string) config('payflow.worker_public_url'), '/').'/webhooks/yookassa',
            'money_flow_notice' => true,
            'terms_url' => route('legal', ['doc' => 'terms']),
        ]);
    }

    /** @return array<string, mixed> */
    private function account(PaymentAccount $account, PaymentAccountState $state, PaymentAccountManager $manager): array
    {
        $status = $state->status($account);
        $connectedBy = $manager->connectedBy($account) ?? $this->workspace()->members()->wherePivot('role', 'owner')->first();

        return [
            'driver' => $account->driver,
            'mode' => PaymentAccountState::mode($account),
            'status' => $status,
            'display_name' => $account->credential('display_name'),
            'key_hint' => PaymentAccountState::keyHint($account),
            'country' => $this->workspace()->country,
            'charges_enabled' => $status === 'active' && $state->supportedCurrencies($account) !== [],
            'webhook_url_masked' => $account->webhook_endpoint_id !== null ? rtrim((string) config('payflow.worker_public_url'), '/').'/webhooks/stripe/accounts/••••'.substr((string) $account->webhook_endpoint_id, -4) : null,
            'missing_permissions' => [],
            'supported_currencies' => $state->supportedCurrencies($account),
            'last_event_at' => null,
            'last_error' => null,
            'connected_by' => UserRef::of($connectedBy) ?? ['id' => 0, 'name' => ''],
            'created_at' => Iso::dateTime($account->created_at),
        ];
    }

    public function stripe(Request $request, PaymentAccountManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'restricted_key' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:200'],
        ]);
        $manager->connectStripe($this->workspace(), $this->user($request), (string) $data['restricted_key']);

        return $this->back('payment_account_connected');
    }

    public function verify(PaymentAccountState $state): RedirectResponse
    {
        $account = $state->live($this->workspace());

        return $account !== null ? $this->back('payment_account_verified') : redirect()->back(303);
    }

    public function yookassa(Request $request, PaymentAccountManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'shop_id' => ['required', 'string', 'regex:/^\d{4,10}$/'],
            'secret_key' => ['required', 'string', 'regex:/^(live|test)_[A-Za-z0-9_-]{20,}$/'],
        ]);
        $manager->connectYooKassa($this->workspace(), $this->user($request), (string) $data['shop_id'], (string) $data['secret_key']);

        return $this->back('payment_account_connected');
    }

    public function demo(Request $request, PaymentAccountManager $manager): RedirectResponse
    {
        $manager->connectFake($this->workspace(), $this->user($request));

        return $this->back('payment_account_connected');
    }

    public function disconnect(Request $request, PaymentAccountManager $manager): RedirectResponse
    {
        $manager->disconnect($this->workspace(), $this->user($request));

        return $this->back('payment_account_disconnected');
    }
}
