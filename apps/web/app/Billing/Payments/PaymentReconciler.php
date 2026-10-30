<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\BillingException;
use App\Models\PaymentLinkRecord;
use Illuminate\Support\Facades\Log;

final readonly class PaymentReconciler
{
    public function __construct(private PaymentLinks $links) {}

    /** @return array{checked: int, changed: int, errors: int} */
    public function run(): array
    {
        $summary = ['checked' => 0, 'changed' => 0, 'errors' => 0];
        $staleBefore = now()->subMinutes(max(1, (int) config('billing.payments.reconcile_after_minutes', 25)));

        $open = PaymentLinkRecord::query()
            ->with('account')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->where('created_at', '>=', now()->subDays(max(1, (int) config('billing.payments.reconcile_window_days', 30))))
            ->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $staleBefore))
            ->orderBy('last_checked_at')
            ->limit(max(1, (int) config('billing.payments.reconcile_batch', 200)))
            ->get();

        foreach ($open as $record) {
            $summary['checked']++;
            $before = $record->status;

            try {
                $after = $this->links->refresh($record)->status;
            } catch (BillingException $exception) {
                $summary['errors']++;
                $record->forceFill(['last_checked_at' => now()])->save();
                Log::warning('payments.reconcile_failed', ['payment_link_id' => $record->id, 'reason' => $exception->reason]);

                continue;
            }

            if ($after !== $before) {
                $summary['changed']++;
            }
        }

        return $summary;
    }
}
