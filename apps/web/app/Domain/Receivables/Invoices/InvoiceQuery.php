<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Domain\Analytics\Enums\AgingBucket;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\BusinessDate;
use Illuminate\Database\Eloquent\Builder;

final class InvoiceQuery
{
    public const array SORTS = ['due_date', '-due_date', 'balance', '-balance', 'days_overdue', '-days_overdue', 'risk', '-risk', 'issue_date', '-issue_date'];

    public const array STATUS_FILTERS = ['all', 'outstanding', 'draft', 'open', 'due_soon', 'overdue', 'partially_paid', 'paid', 'disputed', 'uncollectible', 'void'];

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public static function status(Builder $query, string $status, string $today): Builder
    {
        $soon = BusinessDate::addDays($today, 3);

        return match ($status) {
            'outstanding' => $query->whereIn('status', Invoice::OUTSTANDING_STATUSES),
            'draft', 'paid', 'disputed', 'uncollectible', 'void' => $query->where('status', $status),
            'overdue' => $query->whereIn('status', Invoice::OPEN_STATUSES)->where('due_date', '<', $today),
            'partially_paid' => $query->where('status', InvoiceStatus::PartiallyPaid->value)->where('due_date', '>=', $today),
            'due_soon' => $query->where('status', InvoiceStatus::Open->value)->whereBetween('due_date', [$today, $soon]),
            'open' => $query->where('status', InvoiceStatus::Open->value)->where('due_date', '>', $soon),
            default => $query,
        };
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public static function aging(Builder $query, ?string $bucket, string $today): Builder
    {
        if ($bucket === null || AgingBucket::tryFrom($bucket) === null) {
            return $query;
        }

        $query->whereIn('status', Invoice::OUTSTANDING_STATUSES);

        return match ($bucket) {
            'current' => $query->where('due_date', '>=', $today),
            'd1_30' => $query->whereBetween('due_date', [BusinessDate::addDays($today, -30), BusinessDate::addDays($today, -1)]),
            'd31_60' => $query->whereBetween('due_date', [BusinessDate::addDays($today, -60), BusinessDate::addDays($today, -31)]),
            'd61_90' => $query->whereBetween('due_date', [BusinessDate::addDays($today, -90), BusinessDate::addDays($today, -61)]),
            default => $query->where('due_date', '<', BusinessDate::addDays($today, -90)),
        };
    }

    /**
     * @param  Builder<Invoice>  $query
     * @param  array{status: string, client_id: int|null, q: string|null, currency: string|null, risk: string|null, aging: string|null, due_from: string|null, due_to: string|null, sort: string}  $filters
     * @return Builder<Invoice>
     */
    public static function apply(Builder $query, array $filters, string $today): Builder
    {
        self::status($query, $filters['status'], $today);
        self::aging($query, $filters['aging'], $today);

        if ($filters['client_id'] !== null) {
            $query->where('client_id', $filters['client_id']);
        }

        if ($filters['currency'] !== null) {
            $query->where('currency', $filters['currency']);
        }

        if ($filters['risk'] !== null) {
            $query->where('risk_band', $filters['risk']);
        }

        if ($filters['due_from'] !== null) {
            $query->where('due_date', '>=', $filters['due_from']);
        }

        if ($filters['due_to'] !== null) {
            $query->where('due_date', '<=', $filters['due_to']);
        }

        if ($filters['q'] !== null) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($filters['q'])).'%';
            $query->where(fn (Builder $inner) => $inner
                ->whereRaw('lower(coalesce(number, \'\')) like ?', [$term])
                ->orWhereRaw('lower(coalesce(po_number, \'\')) like ?', [$term])
                ->orWhereHas('client', fn (Builder $client) => $client->whereRaw('lower(name) like ?', [$term])));
        }

        $direction = str_starts_with($filters['sort'], '-') ? 'desc' : 'asc';

        match (ltrim($filters['sort'], '-')) {
            'balance' => $query->orderByRaw('(total_minor - amount_paid_minor) '.$direction),
            'days_overdue' => $query->orderBy('due_date', $direction === 'asc' ? 'desc' : 'asc'),
            'risk' => $query->orderByRaw('risk_score '.$direction.' nulls last'),
            'issue_date' => $query->orderBy('issue_date', $direction),
            default => $query->orderBy('due_date', $direction),
        };

        return $query->orderBy('id', 'desc');
    }
}
