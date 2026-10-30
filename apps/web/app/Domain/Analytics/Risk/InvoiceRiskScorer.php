<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Risk;

use App\Domain\Analytics\Enums\RiskBand;

final class InvoiceRiskScorer
{
    public const float INTERCEPT = -2.2;

    public const float DEFAULT_LATE_SHARE = 0.35;

    public const array WEIGHTS = [
        'client_late_rate' => 3.0,
        'avg_days_late' => 0.04,
        'other_overdue' => 0.45,
        'large_amount' => 0.5,
        'long_terms' => 0.012,
        'already_overdue' => 0.035,
        'not_viewed' => 0.6,
        'dispute_history' => 0.5,
        'new_client' => 0.3,
    ];

    public static function score(RiskInput $input): RiskResult
    {
        $lateRate = ($input->lateCount + 2 * $input->workspaceLateShare) / ($input->closedCount + 2);
        $ratio = $input->amountRatio > 0 ? $input->amountRatio : 1.0;

        $terms = [
            'client_late_rate' => [$lateRate, ['late' => $input->lateCount, 'closed' => $input->closedCount, 'rate_pct' => (int) round($lateRate * 100)]],
            'avg_days_late' => [min($input->avgDaysLate, 60.0), ['days' => round($input->avgDaysLate, 1)]],
            'other_overdue' => [(float) min($input->otherOverdueInvoices, 4), ['count' => $input->otherOverdueInvoices]],
            'large_amount' => [max(-1.0, min(2.0, log($ratio))), ['ratio' => round($ratio, 1)]],
            'long_terms' => [(float) max($input->termsDays - 30, 0), ['days' => $input->termsDays]],
            'already_overdue' => [(float) min(max($input->daysSinceDue, 0), 60), ['days' => max($input->daysSinceDue, 0)]],
            'not_viewed' => [$input->notViewed ? 1.0 : 0.0, []],
            'dispute_history' => [$input->disputeHistory ? 1.0 : 0.0, []],
            'new_client' => [$input->closedCount < 3 ? 1.0 : 0.0, ['closed' => $input->closedCount]],
        ];

        $z = self::INTERCEPT;
        $factors = [];

        foreach ($terms as $code => [$value, $params]) {
            $weight = self::WEIGHTS[$code];
            $contribution = $weight * $value;
            $z += $contribution;

            if ($contribution > 0.05) {
                $factors[] = [
                    'code' => $code,
                    'weight' => $weight,
                    'value' => round($value, 4),
                    'contribution' => round($contribution, 4),
                    'params' => $params,
                ];
            }
        }

        usort($factors, fn (array $a, array $b): int => $b['contribution'] <=> $a['contribution']);

        $probability = 1 / (1 + exp(-$z));
        $score = (int) round(100 * $probability);

        return new RiskResult(round($z, 4), $probability, $score, self::band($score), array_slice($factors, 0, 3));
    }

    public static function band(int $score): RiskBand
    {
        return match (true) {
            $score < 30 => RiskBand::Low,
            $score < 60 => RiskBand::Medium,
            default => RiskBand::High,
        };
    }

    public static function lateRate(int $late, int $closed, float $workspaceShare): float
    {
        return ($late + 2 * $workspaceShare) / ($closed + 2);
    }
}
