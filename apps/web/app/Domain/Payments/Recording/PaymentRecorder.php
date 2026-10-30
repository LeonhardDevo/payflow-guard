<?php

declare(strict_types=1);

namespace App\Domain\Payments\Recording;

use App\Audit\Auditor;
use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Collections\Enums\TemplateKey;
use App\Domain\Notifications\Email\DomainMailer;
use App\Domain\Notifications\Routing\ReceivablesNotifications;
use App\Domain\Payments\Accounts\PaymentAccountIssues;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Invoices\InvoiceSettlement;
use App\Domain\Receivables\Invoices\InvoiceStateMachine;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class PaymentRecorder
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private InvoiceTimeline $timeline,
        private ClientEmailComposer $composer,
        private DomainMailer $mailer,
        private ReceivablesNotifications $notifications,
        private Auditor $auditor,
        private Clock $clock,
        private PaymentAccountIssues $issues,
    ) {}

    public function recordManual(
        Invoice $invoice,
        User $actor,
        int $amountMinor,
        string $paidOn,
        PaymentMethod $method,
        ?string $reference,
        ?string $note,
        bool $sendReceipt,
        bool $allowOverpayment,
    ): Payment {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid, InvoiceStatus::Disputed, InvoiceStatus::Uncollectible]);

        if ($amountMinor <= 0) {
            throw new PayflowException('overpayment', 422);
        }

        $overpaid = $amountMinor > $invoice->balanceDue();

        if ($overpaid && ! $allowOverpayment) {
            throw new PayflowException('overpayment', 422);
        }

        $timezone = WorkspaceClock::zone($invoice->workspace->timezone);

        $payment = DB::transaction(function () use ($invoice, $actor, $amountMinor, $paidOn, $method, $reference, $note, $overpaid, $timezone): Payment {
            $payment = new Payment;
            $payment->forceFill([
                'workspace_id' => $invoice->workspace_id,
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'amount_minor' => $amountMinor,
                'currency' => $invoice->currency,
                'method' => $method,
                'status' => PaymentStatus::Succeeded,
                'provider' => PaymentProvider::Manual,
                'paid_at' => BusinessDate::toUtc($paidOn, '12:00', $timezone),
                'reference' => $reference,
                'note' => $note,
                'recorded_by' => $actor->id,
            ])->save();

            $this->timeline->record($invoice, InvoiceEventType::PaymentReceived, [
                'payment_id' => $payment->id,
                'amount_minor' => $amountMinor,
                'method' => $method->value,
            ], $actor);

            if ($overpaid) {
                $this->timeline->record($invoice, InvoiceEventType::Overpaid, ['payment_id' => $payment->id, 'amount_minor' => $amountMinor - $invoice->balanceDue()], $actor);
            }

            $this->settlement->settle($invoice, $actor);

            $this->auditor->record(
                'payflow.payment.recorded',
                $payment,
                metadata: ['invoice_id' => $invoice->id, 'amount_minor' => $amountMinor, 'currency' => $invoice->currency, 'method' => $method->value],
                actor: $actor,
                workspace: $invoice->workspace_id,
                subjectLabel: $invoice->label(),
            );

            return $payment;
        });

        $invoice->refresh();

        if ($sendReceipt) {
            $this->sendReceipt($invoice, $payment);
        }

        $this->notifications->paymentReceived($invoice, $payment);

        return $payment;
    }

    public function reverse(Payment $payment, User $actor, string $reason): void
    {
        if ($payment->provider !== PaymentProvider::Manual || $payment->status !== PaymentStatus::Succeeded) {
            throw new PayflowException('payment_not_reversible', 409);
        }

        DB::transaction(function () use ($payment, $actor, $reason): void {
            $payment->forceFill([
                'status' => PaymentStatus::Reversed,
                'reversed_at' => $this->clock->now(),
                'reversed_by' => $actor->id,
                'reverse_reason' => $reason,
            ])->save();

            $invoice = $payment->invoice;
            $this->timeline->record($invoice, InvoiceEventType::PaymentReversed, ['payment_id' => $payment->id, 'amount_minor' => $payment->amount_minor], $actor);
            $this->settlement->settle($invoice, $actor);

            $this->auditor->record(
                'payflow.payment.reversed',
                $payment,
                metadata: ['invoice_id' => $invoice->id, 'amount_minor' => $payment->amount_minor, 'reason' => $reason],
                actor: $actor,
                workspace: $payment->workspace_id,
                subjectLabel: $invoice->label(),
            );
        });
    }

    public function recordProvider(Invoice $invoice, InvoicePaymentLink $link, string $providerPaymentId, int $amountMinor, string $currency, CarbonImmutable $paidAt): ?Payment
    {
        $provider = PaymentProvider::from($link->driver);

        $result = DB::transaction(function () use ($invoice, $link, $provider, $providerPaymentId, $amountMinor, $currency, $paidAt): ?array {
            $existing = Payment::query()->withoutGlobalScopes()
                ->where('provider', $provider->value)
                ->where('provider_payment_id', $providerPaymentId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status !== PaymentStatus::Pending) {
                return null;
            }

            $mismatch = strtoupper($currency) !== $invoice->currency;
            $payment = $existing ?? new Payment;
            $payment->forceFill([
                'workspace_id' => $invoice->workspace_id,
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'amount_minor' => $amountMinor,
                'currency' => strtoupper($currency),
                'method' => PaymentMethod::PaymentLink,
                'status' => $mismatch ? PaymentStatus::Failed : PaymentStatus::Succeeded,
                'provider' => $provider,
                'payment_account_id' => $link->payment_account_id,
                'provider_payment_id' => $providerPaymentId,
                'payment_link_id' => $link->id,
                'paid_at' => $paidAt,
            ])->save();

            if ($mismatch) {
                if ($link->payment_account_id !== null) {
                    $this->issues->providerError((int) $link->payment_account_id, 'currency_mismatch', $invoice->label(), $provider->value);
                }

                return null;
            }

            $this->timeline->record($invoice, InvoiceEventType::PaymentReceived, [
                'payment_id' => $payment->id,
                'amount_minor' => $amountMinor,
                'method' => PaymentMethod::PaymentLink->value,
                'provider' => $provider->value,
            ], actorType: EventActorType::Provider);

            $this->settlement->settle($invoice);

            $this->auditor->record(
                'payflow.payment.provider_recorded',
                $payment,
                metadata: ['invoice_id' => $invoice->id, 'amount_minor' => $amountMinor, 'currency' => $currency, 'provider' => $provider->value],
                workspace: $invoice->workspace_id,
                subjectLabel: $invoice->label(),
                system: true,
            );

            return ['payment' => $payment];
        });

        if ($result === null) {
            return null;
        }

        $payment = $result['payment'];
        $invoice->refresh();
        $this->sendReceipt($invoice, $payment);
        $this->notifications->paymentReceived($invoice, $payment);

        return $payment;
    }

    public function recordFailure(Invoice $invoice, InvoicePaymentLink $link): void
    {
        $pending = Payment::query()->withoutGlobalScopes()
            ->where('payment_link_id', $link->id)
            ->where('status', PaymentStatus::Pending->value)
            ->get();

        foreach ($pending as $payment) {
            $payment->forceFill(['status' => PaymentStatus::Failed])->save();
        }

        $this->timeline->record($invoice, InvoiceEventType::PaymentFailed, ['link_id' => $link->id, 'provider' => $link->driver], actorType: EventActorType::Provider);
    }

    public function refundProvider(Invoice $invoice, InvoicePaymentLink $link, ?int $refundedMinor = null): void
    {
        DB::transaction(function () use ($invoice, $link, $refundedMinor): void {
            $payments = Payment::query()->withoutGlobalScopes()
                ->where('payment_link_id', $link->id)
                ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value])
                ->lockForUpdate()
                ->get();

            foreach ($payments as $payment) {
                $refunded = min($payment->amount_minor, $refundedMinor ?? $payment->amount_minor);
                $payment->forceFill([
                    'refunded_minor' => $refunded,
                    'status' => $refunded >= $payment->amount_minor ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                ])->save();

                $this->timeline->record($invoice, InvoiceEventType::PaymentRefunded, ['payment_id' => $payment->id, 'refunded_minor' => $refunded], actorType: EventActorType::Provider);
                $this->auditor->record(
                    'payflow.payment.refunded',
                    $payment,
                    metadata: ['invoice_id' => $invoice->id, 'refunded_minor' => $refunded],
                    workspace: $invoice->workspace_id,
                    subjectLabel: $invoice->label(),
                    system: true,
                );
            }

            if ($payments->isNotEmpty()) {
                $this->settlement->settle($invoice);
            }
        });
    }

    public function sendReceipt(Invoice $invoice, Payment $payment): void
    {
        $client = $invoice->client;

        if ($client->email === null || $client->email === '') {
            return;
        }

        $locale = $invoice->locale;
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $this->clock->now());
        $balance = $invoice->balanceDue();
        $variables = $this->composer->variables($invoice, $today, null, [
            'payment_amount' => MoneyFormatter::format($payment->amount_minor, $payment->currency, $locale),
            'balance_phrase' => $balance <= 0
                ? (string) trans('payflow.templates.balance_paid', [], $locale)
                : (string) trans('payflow.templates.balance_remaining', ['amount' => MoneyFormatter::format($balance, $invoice->currency, $locale)], $locale),
        ]);
        $email = $this->composer->compose($invoice, TemplateKey::PaymentReceipt, $variables);
        $this->mailer->publish($this->composer->envelope($invoice, $email, ['type' => 'payment_receipt', 'id' => $payment->id], false));
    }
}
