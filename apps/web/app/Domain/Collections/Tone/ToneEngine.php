<?php

declare(strict_types=1);

namespace App\Domain\Collections\Tone;

use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Receivables\Enums\LatePayerClass;
use App\Domain\Receivables\Enums\ToneCeiling;

final class ToneEngine
{
    public static function suggest(ToneInput $input): ToneSuggestion
    {
        $d = $input->daysSinceDue;
        $baseReason = self::baseReason($d);

        if ($input->stepTone !== StepTone::Auto) {
            $tone = Tone::from($input->stepTone->value);
            $reasons = [['code' => 'step_fixed_tone', 'params' => ['tone' => $tone->value]], $baseReason];

            return self::applyCeiling($tone, $reasons, $input->ceiling);
        }

        $rank = match (true) {
            $d <= 7 => Tone::Friendly->rank(),
            $d <= 21 => Tone::Neutral->rank(),
            $d <= 44 => Tone::Firm->rank(),
            default => Tone::Final->rank(),
        };
        $reasons = [$baseReason];

        if ($input->clientClass === LatePayerClass::Chronic && $d >= 1) {
            $rank++;
            $reasons[] = ['code' => 'client_chronic', 'params' => ['avg_days_late' => round($input->avgDaysLate, 1)]];
        }

        if ($input->clientClass === LatePayerClass::Reliable && $d >= 1 && $d <= 14) {
            $rank--;
            $reasons[] = ['code' => 'client_reliable', 'params' => ['on_time_pct' => (int) round(($input->onTimeRate ?? 1.0) * 100)]];
        }

        if ($input->amountRatio >= 2.0 && $d >= 15) {
            $rank++;
            $reasons[] = ['code' => 'large_amount', 'params' => ['ratio' => round($input->amountRatio, 1)]];
        }

        if ($input->remindersSent >= 3 && $d >= 8 && $rank < Tone::Firm->rank()) {
            $rank = Tone::Firm->rank();
            $reasons[] = ['code' => 'many_reminders', 'params' => ['count' => $input->remindersSent]];
        }

        if ($input->otherOverdueInvoices >= 2 && $d >= 1 && $rank < Tone::Neutral->rank()) {
            $rank = Tone::Neutral->rank();
            $reasons[] = ['code' => 'multiple_overdue', 'params' => ['count' => $input->otherOverdueInvoices]];
        }

        $tone = Tone::fromRank($rank);

        if ($tone === Tone::Final && $d < 30) {
            $tone = Tone::Firm;
        }

        return self::applyCeiling($tone, $reasons, $input->ceiling);
    }

    /** @return array{code: string, params: array<string, string|int|float>} */
    private static function baseReason(int $d): array
    {
        return match (true) {
            $d < 0 => ['code' => 'due_in', 'params' => ['days' => -$d]],
            $d === 0 => ['code' => 'due_today', 'params' => []],
            default => ['code' => 'days_overdue', 'params' => ['days' => $d]],
        };
    }

    /** @param  list<array{code: string, params: array<string, string|int|float>}>  $reasons */
    private static function applyCeiling(Tone $tone, array $reasons, ToneCeiling $ceiling): ToneSuggestion
    {
        if ($ceiling !== ToneCeiling::None) {
            $cap = Tone::from($ceiling->value);

            if ($tone->isAbove($cap)) {
                $tone = $cap;
                $reasons[] = ['code' => 'client_tone_ceiling', 'params' => ['ceiling' => $ceiling->value]];
            }
        }

        return new ToneSuggestion($tone, $reasons);
    }
}
