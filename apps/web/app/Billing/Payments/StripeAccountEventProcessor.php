<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Models\PaymentAccount;
use App\Models\PaymentLinkRecord;
use App\Models\ProcessedStripeEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

final readonly class StripeAccountEventProcessor
{
    public const string PROCESSED = 'processed';

    public const string DUPLICATE = 'duplicate';

    public const string IGNORED = 'ignored';

    public function __construct(private PaymentLinks $links) {}

    /**
     * @param  array<string, mixed>  $event
     * @return self::PROCESSED|self::DUPLICATE|self::IGNORED
     */
    public function handle(PaymentAccount $account, array $event): string
    {
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($eventId === '' || $type === '') {
            throw new \InvalidArgumentException('Stripe event must have id and type.');
        }

        $inserted = ProcessedStripeEvent::query()->insertOrIgnore([
            'event_id' => 'acct'.$account->id.':'.$eventId,
            'type' => $type,
            'workspace_id' => $account->workspace_id,
            'processed_at' => now(),
        ]);

        if ($inserted === 0) {
            return self::DUPLICATE;
        }

        /** @var array<string, mixed> $object */
        $object = Arr::get($event, 'data.object', []);
        $created = (int) ($event['created'] ?? time());

        $record = match ($type) {
            'checkout.session.completed' => $this->byLink($account, $object['payment_link'] ?? null),
            'payment_intent.succeeded', 'payment_intent.payment_failed' => $this->byReference($account, $object),
            'charge.refunded' => $this->byPayment($account, $object['payment_intent'] ?? null),
            default => null,
        };

        if ($record === null) {
            return self::IGNORED;
        }

        [$status, $paymentId] = match ($type) {
            'checkout.session.completed' => [
                in_array($object['payment_status'] ?? null, ['paid', 'no_payment_required'], true) ? PaymentStatus::Succeeded : null,
                is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null,
            ],
            'payment_intent.succeeded' => [PaymentStatus::Succeeded, is_string($object['id'] ?? null) ? $object['id'] : null],
            'payment_intent.payment_failed' => [PaymentStatus::Failed, null],
            'charge.refunded' => [($object['refunded'] ?? false) === true ? PaymentStatus::Refunded : null, null],
        };

        if ($status === null) {
            return self::IGNORED;
        }

        $this->links->transition($record, $status, 'stripe.'.$type, $created, $paymentId);

        return self::PROCESSED;
    }

    private function byLink(PaymentAccount $account, mixed $linkId): ?PaymentLinkRecord
    {
        return is_string($linkId) ? $this->query($account)->where('provider_id', $linkId)->first() : null;
    }

    private function byPayment(PaymentAccount $account, mixed $paymentId): ?PaymentLinkRecord
    {
        return is_string($paymentId) ? $this->query($account)->where('provider_payment_id', $paymentId)->first() : null;
    }

    /** @param  array<string, mixed>  $object */
    private function byReference(PaymentAccount $account, array $object): ?PaymentLinkRecord
    {
        $byPayment = $this->byPayment($account, $object['id'] ?? null);

        if ($byPayment !== null) {
            return $byPayment;
        }

        $reference = Arr::get($object, 'metadata.reference');

        if (! is_string($reference) || (string) Arr::get($object, 'metadata.workspace_id') !== (string) $account->workspace_id) {
            return null;
        }

        return $this->query($account)
            ->where('reference', $reference)
            ->where('amount', (int) ($object['amount'] ?? -1))
            ->latest('id')
            ->first();
    }

    /** @return Builder<PaymentLinkRecord> */
    private function query(PaymentAccount $account): Builder
    {
        return PaymentLinkRecord::query()
            ->where('payment_account_id', $account->id)
            ->where('workspace_id', $account->workspace_id)
            ->where('driver', 'stripe');
    }
}
