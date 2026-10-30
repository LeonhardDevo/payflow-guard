<?php

declare(strict_types=1);

namespace App\Domain\Payments\Links;

use App\Billing\BillingException;
use App\Billing\Payments\PaymentLinks;
use App\Domain\Payments\Enums\LinkDeactivationReason;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\Clock;
use Illuminate\Support\Facades\Log;

final readonly class PaymentLinkSync
{
    public function __construct(
        private PaymentLinks $links,
        private PaymentLinkService $service,
        private Clock $clock,
    ) {}

    /** @return list<array{id: int, workspace_id: int}> */
    public function candidates(int $limit): array
    {
        $now = $this->clock->now();

        return array_values(InvoicePaymentLink::query()
            ->whereNotNull('payment_links.invoice_id')
            ->where('payment_links.active', true)
            ->join('invoices', 'invoices.id', '=', 'payment_links.invoice_id')
            ->whereIn('invoices.status', Invoice::OPEN_STATUSES)
            ->whereNull('invoices.deleted_at')
            ->where('invoices.sent_at', '<', $now->subMinutes((int) config('payflow.payment_links.sync_grace_minutes', 10)))
            ->where(fn ($query) => $query->whereNull('payment_links.last_synced_at')
                ->orWhere('payment_links.last_synced_at', '<', $now->subMinutes((int) config('payflow.payment_links.sync_after_minutes', 30))))
            ->orderByRaw('payment_links.last_synced_at ASC NULLS FIRST')
            ->orderBy('payment_links.id')
            ->limit(max(1, min($limit, 500)))
            ->get(['payment_links.id', 'payment_links.workspace_id'])
            ->map(fn (InvoicePaymentLink $link): array => ['id' => $link->id, 'workspace_id' => $link->workspace_id])
            ->all());
    }

    /** @return array{recorded: int, deactivated: bool} */
    public function sync(InvoicePaymentLink $link): array
    {
        $invoice = Invoice::query()->withoutGlobalScopes()->whereKey($link->invoice_id)->first();
        $now = $this->clock->now();

        if ($invoice === null || ! $link->active) {
            return ['recorded' => 0, 'deactivated' => false];
        }

        if (! $invoice->isOpen()) {
            $this->service->deactivateLink($invoice, $link, LinkDeactivationReason::Paid);

            return ['recorded' => 0, 'deactivated' => true];
        }

        $before = Payment::query()->withoutGlobalScopes()->where('payment_link_id', $link->id)->count();

        try {
            $this->links->refresh($link);
        } catch (BillingException $exception) {
            Log::warning('payflow.payment_link_sync_failed', ['link_id' => $link->id, 'reason' => $exception->reason]);
        }

        $link->refresh();
        $link->forceFill(['last_synced_at' => $now])->save();
        $recorded = Payment::query()->withoutGlobalScopes()->where('payment_link_id', $link->id)->count() - $before;

        if ($link->active && $link->isExpired($now)) {
            $this->service->deactivateLink($invoice, $link, LinkDeactivationReason::Expired);

            return ['recorded' => max(0, $recorded), 'deactivated' => true];
        }

        return ['recorded' => max(0, $recorded), 'deactivated' => ! $link->active];
    }
}
