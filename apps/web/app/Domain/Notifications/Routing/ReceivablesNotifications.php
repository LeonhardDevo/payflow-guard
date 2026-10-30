<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Routing;

use App\Domain\Collections\Models\Reminder;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Time\BusinessDate;

final readonly class ReceivablesNotifications
{
    public const array PROVIDER_ERRORS = ['provider_unavailable', 'currency_mismatch', 'currency_unsupported', 'receipt_email_missing'];

    public function __construct(private NotificationRouter $router) {}

    public function paymentReceived(Invoice $invoice, Payment $payment): int
    {
        $client = $invoice->client;
        $balance = $invoice->balanceDue();

        return $this->router->send(
            $invoice->workspace_id,
            NotificationEvent::PaymentReceived,
            function (string $locale) use ($invoice, $payment, $client, $balance): array {
                $body = (string) __('payflow.telegram.payment_received.body', [
                    'client' => e($client->name),
                    'amount' => MoneyFormatter::format($payment->amount_minor, $payment->currency, $locale),
                    'number' => e($invoice->label()),
                ]);

                if ($balance > 0) {
                    $body .= ' '.__('payflow.telegram.payment_received.partial', ['balance' => MoneyFormatter::format($balance, $invoice->currency, $locale)]);
                }

                $body .= "\n".__('payflow.telegram.payment_received.method', ['method' => __('payflow.methods.'.$payment->method->value)]);

                return ['title' => (string) __('payflow.telegram.payment_received.title'), 'body' => $body];
            },
            ['type' => 'payment', 'id' => $payment->id],
            $client->owner_user_id,
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_invoice'), 'url' => route('invoices.show', $invoice->id)]],
        );
    }

    public function reminderFailed(Reminder $reminder): int
    {
        $invoice = $reminder->invoice;
        $client = $reminder->client;

        return $this->router->send(
            $reminder->workspace_id,
            NotificationEvent::ReminderFailed,
            fn (string $locale): array => [
                'title' => (string) __('payflow.telegram.reminder_failed.title'),
                'body' => (string) __('payflow.telegram.reminder_failed.body', [
                    'tone' => mb_strtolower((string) __('payflow.tones.'.$reminder->tone->value)),
                    'number' => e($invoice->label()),
                    'client' => e($client->name),
                    'email' => e((string) $reminder->recipient_email),
                    'error' => e(mb_substr((string) $reminder->error, 0, 200)),
                ]),
            ],
            ['type' => 'reminder', 'id' => $reminder->id],
            $client->owner_user_id,
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_invoice'), 'url' => route('invoices.show', $invoice->id)]],
        );
    }

    public function reminderEscalation(Invoice $invoice, Reminder $reminder, string $today): int
    {
        $client = $invoice->client;
        $days = max(0, BusinessDate::diff($invoice->dueDate(), $today));

        return $this->router->send(
            $invoice->workspace_id,
            NotificationEvent::CollectionsEscalation,
            fn (string $locale): array => [
                'title' => (string) __('payflow.telegram.reminder_escalation.title', ['number' => e($invoice->label())]),
                'body' => (string) __('payflow.telegram.reminder_escalation.body', [
                    'client' => e($client->name),
                    'days' => $days,
                    'days_phrase' => trans_choice('payflow.phrases.days', $days, ['count' => $days]),
                    'amount' => MoneyFormatter::format($invoice->balanceDue(), $invoice->currency, $locale),
                    'tone' => mb_strtolower((string) __('payflow.tones.'.$reminder->tone->value)),
                    'count' => $invoice->reminders_sent_count,
                    'contact' => e(trim(($client->contact_name ?? $client->name).($client->phone !== null ? ', '.$client->phone : ''))),
                ]),
            ],
            ['type' => 'reminder_escalation', 'id' => $reminder->id],
            $client->owner_user_id,
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_invoice'), 'url' => route('invoices.show', $invoice->id)]],
        );
    }

    public function collectionsEscalation(Invoice $invoice, string $today): int
    {
        $client = $invoice->client;
        $days = max(0, BusinessDate::diff($invoice->dueDate(), $today));

        return $this->router->send(
            $invoice->workspace_id,
            NotificationEvent::CollectionsEscalation,
            fn (string $locale): array => [
                'title' => (string) __('payflow.telegram.collections_escalation.title'),
                'body' => (string) __('payflow.telegram.collections_escalation.body', [
                    'number' => e($invoice->label()),
                    'client' => e($client->name),
                    'days' => $days,
                    'days_phrase' => trans_choice('payflow.phrases.days', $days, ['count' => $days]),
                    'amount' => MoneyFormatter::format($invoice->balanceDue(), $invoice->currency, $locale),
                ]),
            ],
            ['type' => 'collections_escalation', 'id' => $invoice->id],
            $client->owner_user_id,
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_invoice'), 'url' => route('invoices.show', $invoice->id)]],
        );
    }

    public function remindersOptedOut(Client $client): int
    {
        return $this->router->send(
            $client->workspace_id,
            NotificationEvent::RemindersOptedOut,
            fn (): array => [
                'title' => (string) __('payflow.telegram.reminders_opted_out.title', ['client' => e($client->name)]),
                'body' => (string) __('payflow.telegram.reminders_opted_out.body'),
            ],
            ['type' => 'client_opt_out', 'id' => $client->id],
            $client->owner_user_id,
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_client'), 'url' => route('clients.show', $client->id)]],
        );
    }

    public function clientReportedPaid(Invoice $invoice): int
    {
        $client = $invoice->client;

        return $this->router->send(
            $invoice->workspace_id,
            NotificationEvent::ClientReportedPaid,
            fn (): array => [
                'title' => (string) __('payflow.telegram.client_reported_paid.title', ['client' => e($client->name), 'number' => e($invoice->label())]),
                'body' => (string) __('payflow.telegram.client_reported_paid.body', [
                    'reference' => e($invoice->client_reported_reference ?? (string) __('payflow.telegram.client_reported_paid.no_reference')),
                ]),
            ],
            ['type' => 'client_reported_paid', 'id' => $invoice->id],
            $client->owner_user_id,
            buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_invoice'), 'url' => route('invoices.show', $invoice->id)]],
        );
    }

    public function paymentProviderError(int $workspaceId, int $accountId, string $provider, string $errorCode, ?int $alertId = null): int
    {
        $code = in_array($errorCode, self::PROVIDER_ERRORS, true) ? $errorCode : 'unknown';

        return $this->router->send(
            $workspaceId,
            NotificationEvent::PaymentAccountIssue,
            fn (): array => [
                'title' => (string) __('payflow.telegram.payment_provider_error.title'),
                'body' => (string) __('payflow.telegram.payment_provider_error.body', [
                    'provider' => e((string) __('payflow.providers.'.$provider)),
                    'error' => (string) __('payflow.telegram.payment_provider_error.errors.'.$code),
                ]),
            ],
            ['type' => 'payment_account', 'id' => $accountId],
            forced: true,
            buttons: fn (): array => self::settingsButtons($alertId),
            alertId: $alertId,
        );
    }

    public function paymentAccountRestricted(int $workspaceId, int $accountId, string $provider, string $reason, ?int $alertId = null): int
    {
        $code = in_array($reason, ['unauthorized', 'forbidden'], true) ? $reason : 'unauthorized';

        return $this->router->send(
            $workspaceId,
            NotificationEvent::PaymentAccountIssue,
            fn (): array => [
                'title' => (string) __('payflow.telegram.payment_account_restricted.title'),
                'body' => (string) __('payflow.telegram.payment_account_restricted.body', [
                    'provider' => e((string) __('payflow.providers.'.$provider)),
                    'reason' => (string) __('payflow.telegram.payment_account_restricted.reasons.'.$code),
                ]),
            ],
            ['type' => 'payment_account', 'id' => $accountId],
            forced: true,
            buttons: fn (): array => self::settingsButtons($alertId),
            alertId: $alertId,
        );
    }

    /** @return list<array{text: string, url?: string, callback?: string}> */
    private static function settingsButtons(?int $alertId): array
    {
        $buttons = [['text' => (string) __('payflow.telegram.open_settings'), 'url' => route('settings.payments')]];

        if ($alertId !== null) {
            $buttons[] = ['text' => (string) __('payflow.telegram.buttons.acknowledge'), 'callback' => 'pf.ack:'.$alertId];
        }

        return $buttons;
    }
}
