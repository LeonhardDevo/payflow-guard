<?php

declare(strict_types=1);

namespace App\Domain\Receivables\LateFees;

use App\Domain\Receivables\Clients\ClientTerms;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\BusinessDate;
use Carbon\CarbonInterface;

final class LateFees
{
    public static function suggest(Invoice $invoice, CarbonInterface|string $today): ?LateFeeSuggestion
    {
        if (! $invoice->isOpen()) {
            return null;
        }

        $client = $invoice->client;
        $terms = ClientTerms::lateFeeTerms($client);

        if (! $terms->isActive()) {
            return null;
        }

        $daysOverdue = max(0, BusinessDate::diff($invoice->dueDate(), $today));
        $applied = $invoice->late_fee_applied_minor;
        $balance = $invoice->balanceDue() - $applied;
        $total = $invoice->total_minor - $applied;
        $fee = LateFeeCalculator::fee($terms, $balance, $total, $daysOverdue);

        if ($fee <= 0) {
            return null;
        }

        return new LateFeeSuggestion(
            amountMinor: max(0, $fee - $applied),
            chargeableDays: LateFeeCalculator::chargeableDays($terms, $daysOverdue),
            terms: $terms,
            source: ClientTerms::lateFeeSource($client),
            alreadyAppliedMinor: $applied,
            currency: $invoice->currency,
        );
    }
}
