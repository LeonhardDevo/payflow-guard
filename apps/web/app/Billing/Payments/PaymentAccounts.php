<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Audit\Auditor;
use App\Billing\BillingException;
use App\Billing\YooKassa\YooKassaClient;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Models\Workspace;

final readonly class PaymentAccounts
{
    public function __construct(private Auditor $auditor) {}

    public function connectStripe(Workspace $workspace, string $restrictedKey, ?User $actor = null): PaymentAccount
    {
        if (preg_match('/^(rk|sk)_(test|live)_[A-Za-z0-9]+$/', $restrictedKey) !== 1) {
            throw new BillingException('payment_account_invalid');
        }

        $account = PaymentAccount::query()->firstOrNew(['workspace_id' => $workspace->id, 'driver' => 'stripe']);
        $account->credentials = ['restricted_key' => $restrictedKey];
        $account->save();

        $webhook = (new StripePaymentGateway(StripePaymentGateway::client($restrictedKey)))->registerWebhook(
            $this->webhookUrl($account),
            ['workspace_id' => (string) $workspace->id, 'payment_account_id' => (string) $account->id],
        );

        $account->forceFill([
            'credentials' => ['restricted_key' => $restrictedKey, 'webhook_secret' => $webhook['secret']],
            'webhook_endpoint_id' => $webhook['id'],
            'connected_at' => now(),
        ])->save();

        $this->audit($workspace, $account, $actor);

        return $account;
    }

    public function connectYooKassa(Workspace $workspace, string $shopId, string $secretKey, bool $receipts, ?User $actor = null): PaymentAccount
    {
        if (preg_match('/^\d{1,12}$/', $shopId) !== 1 || preg_match('/^(test|live)_[A-Za-z0-9_-]+$/', $secretKey) !== 1) {
            throw new BillingException('payment_account_invalid');
        }

        YooKassaClient::forShop($shopId, $secretKey)->me();

        $account = PaymentAccount::query()->firstOrNew(['workspace_id' => $workspace->id, 'driver' => 'yookassa']);
        $account->forceFill([
            'credentials' => ['shop_id' => $shopId, 'secret_key' => $secretKey, 'receipts' => $receipts ? '1' : '0'],
            'connected_at' => now(),
        ])->save();

        $this->audit($workspace, $account, $actor);

        return $account;
    }

    public function webhookUrl(PaymentAccount $account): string
    {
        $base = config('billing.payments.webhook_base_url');

        if (! is_string($base) || ! str_starts_with($base, 'https://')) {
            throw new BillingException('payment_webhook_url_missing');
        }

        return rtrim($base, '/').'/webhooks/payments/stripe/'.$account->id;
    }

    private function audit(Workspace $workspace, PaymentAccount $account, ?User $actor): void
    {
        $this->auditor->record(
            'payments.account_connected',
            $account,
            metadata: ['driver' => $account->driver],
            actor: $actor,
            workspace: $workspace,
            subjectLabel: $account->driver,
        );
    }
}
