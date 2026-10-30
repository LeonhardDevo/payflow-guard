<?php

declare(strict_types=1);

namespace App\Domain\Payments\Listeners;

use App\Billing\Payments\PaymentLinkStatusChanged;
use App\Billing\Payments\PaymentStatus as LinkStatus;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Payments\Recording\PaymentRecorder;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\Clock;
use Carbon\CarbonImmutable;

final readonly class ApplyProviderEvent
{
    public function __construct(
        private PaymentRecorder $recorder,
        private Clock $clock,
    ) {}

    public function handle(PaymentLinkStatusChanged $event): void
    {
        $link = InvoicePaymentLink::query()->whereKey($event->link->id)->whereNotNull('invoice_id')->first();

        if ($link === null) {
            return;
        }

        $invoice = Invoice::query()->withoutGlobalScopes()->whereKey($link->invoice_id)->where('workspace_id', $link->workspace_id)->first();

        if ($invoice === null) {
            return;
        }

        match ($event->link->status) {
            LinkStatus::Succeeded => $this->recorder->recordProvider(
                $invoice,
                $link,
                self::providerPaymentId($link),
                $link->amount,
                $link->currency,
                $link->paid_at !== null ? CarbonImmutable::parse($link->paid_at) : $this->clock->now(),
            ),
            LinkStatus::Failed => $this->recorder->recordFailure($invoice, $link),
            LinkStatus::Refunded => $this->recorder->refundProvider($invoice, $link),
            default => null,
        };
    }

    public static function providerPaymentId(InvoicePaymentLink $link): string
    {
        return $link->provider_payment_id ?? 'pi_'.$link->driver.'_'.substr(hash('sha256', $link->provider_id), 0, 24);
    }
}
