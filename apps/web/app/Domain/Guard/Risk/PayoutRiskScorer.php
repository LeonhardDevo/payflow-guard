<?php

declare(strict_types=1);

namespace App\Domain\Guard\Risk;

use App\Domain\Analytics\Enums\RiskBand;

final class PayoutRiskScorer
{
    public const array POINTS = [
        'cooling_off' => 40,
        'bank_change_risk_high' => 25,
        'bank_change_risk_medium' => 10,
        'account_recently_verified' => 10,
        'first_payout' => 15,
        'unusual_amount' => 20,
        'duplicate_suspected' => 30,
        'split_suspected' => 30,
        'urgent_due' => 10,
    ];

    public static function score(PayoutRiskInput $input): RiskResult
    {
        $signals = [];
        $add = function (string $code, array $params = []) use (&$signals): void {
            $signals[] = ['code' => $code, 'points' => self::POINTS[$code], 'params' => $params];
        };

        if ($input->coolingOff) {
            $add('cooling_off', ['until' => (string) $input->coolingOffUntil]);
        }

        if ($input->accountChangeBand === RiskBand::High) {
            $add('bank_change_risk_high', ['score' => $input->accountChangeScore]);
        } elseif ($input->accountChangeBand === RiskBand::Medium) {
            $add('bank_change_risk_medium', ['score' => $input->accountChangeScore]);
        }

        if (! $input->coolingOff && $input->verifiedDaysAgo !== null && $input->verifiedDaysAgo < 30) {
            $add('account_recently_verified', ['days' => $input->verifiedDaysAgo]);
        }

        if ($input->releasedToAccount === 0) {
            $add('first_payout');
        }

        $median = self::median(array_slice($input->releasedAmounts, 0, 10));

        if (count($input->releasedAmounts) >= 3 && $median > 0 && $input->amountMinor >= 3 * $median) {
            $add('unusual_amount', ['ratio' => number_format($input->amountMinor / $median, 1, '.', ''), 'median_minor' => (int) round($median)]);
        }

        if ($input->duplicateOf !== null) {
            $add('duplicate_suspected', ['number' => $input->duplicateOf]);
        }

        $splitTotal = self::splitTotal($input);

        if ($splitTotal !== null) {
            $add('split_suspected', ['count' => count($input->recentSubmittedAmounts) + 1, 'total_minor' => $splitTotal]);
        }

        if ($input->urgentDue) {
            $add('urgent_due', ['due_date' => (string) $input->dueDate]);
        }

        return RiskResult::fromSignals($signals);
    }

    public static function splitTotal(PayoutRiskInput $input): ?int
    {
        $threshold = $input->dualApprovalFromMinor;

        if ($threshold === null || $input->amountMinor >= $threshold || $input->recentSubmittedAmounts === []) {
            return null;
        }

        $total = $input->amountMinor + array_sum($input->recentSubmittedAmounts);

        return $total >= $threshold ? $total : null;
    }

    /** @param  list<int>  $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
