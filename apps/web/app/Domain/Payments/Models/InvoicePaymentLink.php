<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\LinkDeactivationReason;
use App\Domain\Payments\Enums\PaymentLinkKind;
use App\Domain\Receivables\Models\Invoice;
use App\Models\PaymentLinkRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $invoice_id
 * @property PaymentLinkKind|null $kind
 * @property bool $active
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $deactivated_at
 * @property LinkDeactivationReason|null $deactivation_reason
 * @property CarbonInterface|null $last_synced_at
 * @property-read Invoice|null $invoice
 */
class InvoicePaymentLink extends PaymentLinkRecord
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'kind' => PaymentLinkKind::class,
            'active' => 'boolean',
            'expires_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'deactivation_reason' => LinkDeactivationReason::class,
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    /** @param  Builder<self>  $query */
    public function scopeForInvoices(Builder $query): void
    {
        $query->whereNotNull('invoice_id');
    }

    public static function fromRecord(PaymentLinkRecord $record): self
    {
        return self::query()->findOrFail($record->id);
    }

    public function isExpired(CarbonInterface $now): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte($now);
    }
}
