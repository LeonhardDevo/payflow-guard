<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Audit\Auditor;
use App\Billing\BillingException;
use App\Models\PaymentLinkRecord;
use Illuminate\Support\Facades\DB;

final readonly class PaymentLinks
{
    private const array TRANSITIONS = [
        'pending' => ['succeeded', 'failed', 'canceled'],
        'failed' => ['pending', 'succeeded', 'canceled'],
        'canceled' => ['succeeded'],
        'succeeded' => ['refunded'],
        'refunded' => [],
    ];

    public function __construct(
        private PaymentGateways $gateways,
        private Auditor $auditor,
    ) {}

    public function create(PaymentRequest $request): PaymentLinkRecord
    {
        $existing = PaymentLinkRecord::query()
            ->where('workspace_id', $request->workspace->id)
            ->where('reference', $request->reference)
            ->where('amount', $request->money->amount)
            ->where('currency', $request->money->currency)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = $this->gateways->accountFor($request->workspace, $request->money->currency);
        $gateway = $this->gateways->forAccount($account);
        $link = $gateway->createPaymentLink($request);

        $record = PaymentLinkRecord::query()->firstOrCreate(
            ['driver' => $link->driver, 'provider_id' => $link->providerId],
            [
                'workspace_id' => $request->workspace->id,
                'payment_account_id' => $account?->id,
                'reference' => $request->reference,
                'url' => $link->url,
                'amount' => $link->money->amount,
                'currency' => $link->money->currency,
                'status' => PaymentStatus::Pending,
            ],
        );

        $this->auditor->record(
            'payments.link_created',
            $record,
            metadata: ['driver' => $record->driver, 'reference' => $record->reference, 'amount' => $record->amount, 'currency' => $record->currency],
            workspace: $request->workspace->id,
            subjectLabel: $record->reference,
        );

        return $record;
    }

    public function refresh(PaymentLinkRecord $record, string $source = 'reconcile'): PaymentLinkRecord
    {
        $status = $this->gateways->forAccount($record->account)->status($record->toLink());
        $record->forceFill(['last_checked_at' => now()])->save();

        return $this->transition($record, $status, $source);
    }

    public function deactivate(PaymentLinkRecord $record): PaymentLinkRecord
    {
        if (! in_array($record->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
            throw new BillingException('not_supported');
        }

        $this->gateways->forAccount($record->account)->deactivate($record->toLink());

        return $this->transition($record, PaymentStatus::Canceled, 'deactivate');
    }

    public function transition(PaymentLinkRecord $record, PaymentStatus $status, string $source, ?int $eventCreated = null, ?string $providerPaymentId = null): PaymentLinkRecord
    {
        return DB::transaction(function () use ($record, $status, $source, $eventCreated, $providerPaymentId): PaymentLinkRecord {
            $locked = PaymentLinkRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if ($providerPaymentId !== null && $locked->provider_payment_id === null) {
                $locked->provider_payment_id = $providerPaymentId;
            }

            $stale = $eventCreated !== null && $locked->last_event_at !== null && $eventCreated < $locked->last_event_at;

            if ($previous === $status || $stale || ! in_array($status->value, self::TRANSITIONS[$previous->value], true)) {
                $locked->save();

                return $locked;
            }

            $locked->status = $status;
            $locked->last_event_at = $eventCreated ?? $locked->last_event_at;

            if ($status === PaymentStatus::Succeeded) {
                $locked->paid_at = now();
            }

            $locked->save();

            $this->auditor->record(
                'payments.link_status_changed',
                $locked,
                ['status' => [$previous->value, $status->value]],
                ['source' => $source, 'driver' => $locked->driver],
                workspace: $locked->workspace_id,
                subjectLabel: $locked->reference,
                system: true,
            );

            DB::afterCommit(fn () => PaymentLinkStatusChanged::dispatch($locked, $previous, $source));

            return $locked;
        });
    }
}
