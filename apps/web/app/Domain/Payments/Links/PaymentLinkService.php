<?php

declare(strict_types=1);

namespace App\Domain\Payments\Links;

use App\Billing\BillingException;
use App\Billing\Payments\Money;
use App\Billing\Payments\PaymentLinks;
use App\Billing\Payments\PaymentRequest;
use App\Billing\Payments\PaymentStatus as LinkStatus;
use App\Domain\Payments\Accounts\PaymentAccountIssues;
use App\Domain\Payments\Enums\LinkDeactivationReason;
use App\Domain\Payments\Enums\PayableReason;
use App\Domain\Payments\Enums\PaymentLinkKind;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Time\Clock;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Log;

final readonly class PaymentLinkService
{
    public function __construct(
        private PaymentLinks $links,
        private PaymentAccountState $accounts,
        private InvoiceTimeline $timeline,
        private Clock $clock,
        private PaymentAccountIssues $issues,
    ) {}

    public function payableReason(Invoice $invoice): ?PayableReason
    {
        if (! $invoice->isOpen() || $invoice->balanceDue() <= 0) {
            return PayableReason::NotOpen;
        }

        if ($this->accounts->live($invoice->workspace_id) === null) {
            return PayableReason::NoAccount;
        }

        $account = $this->accounts->forCurrency($invoice->workspace_id, $invoice->currency);

        if ($account === null) {
            return PayableReason::CurrencyNotSupported;
        }

        if ($this->accounts->isRestricted($account)) {
            return PayableReason::AccountRestricted;
        }

        return null;
    }

    public function active(Invoice $invoice): ?InvoicePaymentLink
    {
        return InvoicePaymentLink::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('invoice_id', $invoice->id)
            ->where('active', true)
            ->latest('id')
            ->first();
    }

    public function linkKind(Invoice $invoice): ?PaymentLinkKind
    {
        $account = $this->accounts->forCurrency($invoice->workspace_id, $invoice->currency);

        if ($account === null) {
            return null;
        }

        return $account->driver === 'yookassa' ? PaymentLinkKind::Single : PaymentLinkKind::Reusable;
    }

    public function ensureActive(Invoice $invoice, bool $onPay = false): ?InvoicePaymentLink
    {
        if ($this->payableReason($invoice) !== null) {
            return null;
        }

        $account = $this->accounts->forCurrency($invoice->workspace_id, $invoice->currency);

        if (! $account instanceof PaymentAccount) {
            return null;
        }

        $kind = $account->driver === 'yookassa' ? PaymentLinkKind::Single : PaymentLinkKind::Reusable;
        $now = $this->clock->now();
        $current = $this->active($invoice);

        if ($current !== null) {
            if ($current->amount === $invoice->balanceDue() && ! $current->isExpired($now) && $current->status === LinkStatus::Pending) {
                return $current;
            }

            $this->deactivateLink($invoice, $current, $current->isExpired($now) ? LinkDeactivationReason::Expired : LinkDeactivationReason::AmountChanged);
        }

        if ($kind === PaymentLinkKind::Single && ! $onPay) {
            return null;
        }

        $sequence = InvoicePaymentLink::query()->where('invoice_id', $invoice->id)->count() + 1;

        try {
            $record = $this->links->create(new PaymentRequest(
                workspace: $invoice->workspace,
                reference: 'invoice:'.$invoice->id.':'.$sequence,
                description: (string) trans('payflow.payments.invoice_description', ['number' => $invoice->label()], $invoice->locale),
                money: Money::of($invoice->balanceDue(), $invoice->currency),
                returnUrl: route('public.invoices.thanks', ['token' => $invoice->public_token]),
                customerEmail: $invoice->client->email,
                metadata: ['pf_invoice_id' => (string) $invoice->id, 'pf_workspace_id' => (string) $invoice->workspace_id],
            ));
        } catch (BillingException $exception) {
            Log::warning('payflow.payment_link_failed', ['invoice_id' => $invoice->id, 'reason' => $exception->reason]);

            if ($this->issues->failed($account, $exception)) {
                $this->deactivateAccountLinks($account);
            }

            return null;
        }

        $link = InvoicePaymentLink::fromRecord($record);
        $link->forceFill([
            'invoice_id' => $invoice->id,
            'kind' => $kind,
            'active' => true,
            'expires_at' => $kind === PaymentLinkKind::Single ? $now->addMinutes((int) config('payflow.yookassa_link_ttl_minutes', 60)) : null,
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ])->save();

        $this->timeline->record($invoice, InvoiceEventType::LinkCreated, ['driver' => $link->driver, 'amount_minor' => $link->amount]);

        return $link;
    }

    public function deactivateAccountLinks(PaymentAccount $account): int
    {
        $links = InvoicePaymentLink::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('payment_account_id', $account->id)
            ->whereNotNull('invoice_id')
            ->where('active', true)
            ->get();

        foreach ($links as $link) {
            $invoice = Invoice::query()->forWorkspace($account->workspace_id)->withTrashed()->find($link->invoice_id);

            if ($invoice !== null) {
                $this->deactivateLink($invoice, $link, LinkDeactivationReason::AccountRestricted, remote: false);
            }
        }

        return $links->count();
    }

    public function deactivate(Invoice $invoice, LinkDeactivationReason $reason): void
    {
        $current = $this->active($invoice);

        if ($current !== null) {
            $this->deactivateLink($invoice, $current, $reason);
        }
    }

    public function deactivateLink(Invoice $invoice, InvoicePaymentLink $link, LinkDeactivationReason $reason, bool $remote = true): void
    {
        $link->forceFill([
            'active' => false,
            'deactivated_at' => $this->clock->now(),
            'deactivation_reason' => $reason,
        ])->save();

        if ($remote && in_array($link->status, [LinkStatus::Pending, LinkStatus::Failed], true)) {
            try {
                $this->links->deactivate($link);
            } catch (BillingException $exception) {
                Log::warning('payflow.payment_link_deactivate_failed', ['link_id' => $link->id, 'reason' => $exception->reason]);
            }
        }

        $this->timeline->record($invoice, InvoiceEventType::LinkDeactivated, ['reason' => $reason->value]);
    }
}
