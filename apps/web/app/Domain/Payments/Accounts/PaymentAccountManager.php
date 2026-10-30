<?php

declare(strict_types=1);

namespace App\Domain\Payments\Accounts;

use App\Audit\Auditor;
use App\Billing\BillingException;
use App\Billing\Payments\PaymentAccounts;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Payments\Enums\LinkDeactivationReason;
use App\Domain\Payments\Links\PaymentAccountState;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Support\Tokens;
use App\Domain\Shared\Time\Clock;
use App\Models\AuditLog;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class PaymentAccountManager
{
    public const array STRIPE_PERMISSIONS = [
        'products_write', 'prices_write', 'payment_links_write', 'checkout_sessions_read', 'payment_intents_read', 'charges_read', 'webhook_endpoints_write',
    ];

    public function __construct(
        private PaymentAccounts $accounts,
        private PaymentAccountState $state,
        private PaymentLinkService $links,
        private GuardAlerts $alerts,
        private Auditor $auditor,
        private Clock $clock,
        private PaymentAccountIssues $issues,
    ) {}

    public function connectStripe(Workspace $workspace, User $actor, string $restrictedKey): PaymentAccount
    {
        if (str_starts_with($restrictedKey, 'sk_')) {
            throw new PayflowException('secret_key_refused', 422);
        }

        if (preg_match('/^rk_(test|live)_[A-Za-z0-9]{20,}$/', $restrictedKey) !== 1) {
            throw new PayflowException('payment_account_invalid', 422);
        }

        $replacing = $this->state->live($workspace)?->driver === 'stripe';

        try {
            $account = $this->accounts->connectStripe($workspace, $restrictedKey, $actor);
        } catch (BillingException) {
            throw new PayflowException('payment_provider', 422);
        }

        $this->disconnectOthers($workspace, $account);
        $this->connected($workspace, $actor, $account, $replacing ? 'payflow.payment_account.key_replaced' : 'payflow.payment_account.connected');

        return $account;
    }

    public function connectYooKassa(Workspace $workspace, User $actor, string $shopId, string $secretKey): PaymentAccount
    {
        try {
            $account = $this->accounts->connectYooKassa($workspace, $shopId, $secretKey, (bool) config('billing.yookassa.receipts', false), $actor);
        } catch (BillingException) {
            throw new PayflowException('payment_account_invalid', 422);
        }

        $this->disconnectOthers($workspace, $account);
        $this->connected($workspace, $actor, $account, 'payflow.payment_account.connected');

        return $account;
    }

    public function connectFake(Workspace $workspace, ?User $actor, ?string $externalId = null): PaymentAccount
    {
        if (config('payflow.allow_fake_payments') !== true) {
            throw new PayflowException('payment_account_invalid', 403);
        }

        $account = PaymentAccount::query()->firstOrNew(['workspace_id' => $workspace->id, 'driver' => 'fake']);
        $account->forceFill([
            'credentials' => [
                'external_account_id' => $externalId ?? 'acct_fake_'.Tokens::base62(16),
                'endpoint_key' => $account->credential('endpoint_key') ?? Tokens::base62(32),
                'webhook_secret' => $account->credential('webhook_secret') ?? 'whsec_fake_'.Tokens::base62(32),
            ],
            'connected_at' => $this->clock->now(),
        ])->save();

        $this->disconnectOthers($workspace, $account);
        $this->connected($workspace, $actor, $account, 'payflow.payment_account.connected');

        return $account;
    }

    public function disconnect(Workspace $workspace, User $actor): void
    {
        $account = $this->state->live($workspace);

        if ($account === null) {
            return;
        }

        DB::transaction(function () use ($workspace, $actor, $account): void {
            $this->deactivateLinks($workspace, LinkDeactivationReason::AccountDisconnected);
            $account->forceFill(['credentials' => [], 'connected_at' => null, 'webhook_endpoint_id' => null])->save();
            $this->alerts->resolve($workspace->id, 'par:'.$account->id, $actor);
            $this->alerts->resolve($workspace->id, 'ppe:'.$account->id, $actor);
            $this->auditor->record('payflow.payment_account.disconnected', $account, metadata: ['driver' => $account->driver], actor: $actor, workspace: $workspace, subjectLabel: $account->driver);
        });
    }

    public function connectedBy(PaymentAccount $account): ?User
    {
        $actorId = AuditLog::query()
            ->where('workspace_id', $account->workspace_id)
            ->whereIn('action', ['payflow.payment_account.connected', 'payflow.payment_account.key_replaced', 'payments.account_connected'])
            ->where('subject_id', (string) $account->id)
            ->latest('id')
            ->value('actor_id');

        return is_numeric($actorId) ? User::query()->find((int) $actorId) : null;
    }

    private function connected(Workspace $workspace, ?User $actor, PaymentAccount $account, string $action): void
    {
        $this->auditor->record($action, $account, metadata: ['driver' => $account->driver, 'key_hint' => PaymentAccountState::keyHint($account)], actor: $actor, workspace: $workspace, subjectLabel: $account->driver, system: $actor === null);
        $this->issues->cleared($account, $actor);

        Invoice::query()->forWorkspace($workspace)
            ->whereIn('status', Invoice::OPEN_STATUSES)
            ->whereNotNull('sent_at')
            ->get()
            ->each(fn (Invoice $invoice) => $this->links->ensureActive($invoice));
    }

    private function disconnectOthers(Workspace $workspace, PaymentAccount $keep): void
    {
        $others = PaymentAccount::query()->where('workspace_id', $workspace->id)->whereKeyNot($keep->id)->whereNotNull('connected_at')->get();

        if ($others->isEmpty()) {
            return;
        }

        $this->deactivateLinks($workspace, LinkDeactivationReason::AccountDisconnected, array_values(array_map('intval', $others->modelKeys())));

        foreach ($others as $other) {
            $other->forceFill(['credentials' => [], 'connected_at' => null])->save();
        }
    }

    /** @param  list<int>|null  $accountIds */
    private function deactivateLinks(Workspace $workspace, LinkDeactivationReason $reason, ?array $accountIds = null): void
    {
        InvoicePaymentLink::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('invoice_id')
            ->where('active', true)
            ->when($accountIds !== null, fn ($query) => $query->whereIn('payment_account_id', $accountIds ?? []))
            ->get()
            ->each(function (InvoicePaymentLink $link) use ($workspace, $reason): void {
                $invoice = Invoice::query()->forWorkspace($workspace)->withTrashed()->find($link->invoice_id);

                if ($invoice !== null) {
                    $this->links->deactivateLink($invoice, $link, $reason);
                }
            });
    }
}
