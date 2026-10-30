<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use App\Domain\Shared\Money\MoneyParser;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardSettingsRequest extends FormRequest
{
    private const string DECIMAL = '/^\d{1,13}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'thresholds' => ['present', 'array', 'max:20'],
            'thresholds.*.currency' => ['required', 'distinct', Rule::in(array_keys((array) config('payflow.currencies', [])))],
            'thresholds.*.auto_approve_below' => ['required', 'string', 'regex:'.self::DECIMAL],
            'thresholds.*.dual_approval_from' => ['required', 'string', 'regex:'.self::DECIMAL, $this->compare('auto_approve_below', true)],
            'thresholds.*.auto_approve_cumulative' => ['nullable', 'string', 'regex:'.self::DECIMAL, $this->compare('auto_approve_below', false)],
            'cooling_off_hours_medium' => ['required', 'integer', 'between:0,168'],
            'cooling_off_hours_high' => ['required', 'integer', 'gte:cooling_off_hours_medium', 'max:336'],
            'allow_telegram_approvals' => ['required', 'boolean'],
            'escalate_pending_after_hours' => ['required', 'integer', 'between:1,168'],
            'guard_trust_delay_hours' => ['required', 'integer', 'between:0,168'],
            'require_second_factor' => ['required', 'boolean'],
            'solo_release_delay_hours' => ['required', 'integer', 'between:0,168'],
        ];
    }

    /** @return array{thresholds: array<string, array{auto_approve_below_minor: int, dual_approval_from_minor: int, auto_approve_cumulative_minor: int|null}>, cooling_off_hours_medium: int, cooling_off_hours_high: int, allow_telegram_approvals: bool, escalate_pending_after_hours: int, guard_trust_delay_hours: int, require_second_factor: bool, solo_release_delay_hours: int} */
    public function payload(): array
    {
        $thresholds = [];

        foreach ((array) $this->input('thresholds', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cumulative = $row['auto_approve_cumulative'] ?? null;
            $thresholds[strtoupper((string) $row['currency'])] = [
                'auto_approve_below_minor' => MoneyParser::toMinor((string) $row['auto_approve_below']),
                'dual_approval_from_minor' => MoneyParser::toMinor((string) $row['dual_approval_from']),
                'auto_approve_cumulative_minor' => is_string($cumulative) && $cumulative !== '' ? MoneyParser::toMinor($cumulative) : null,
            ];
        }

        ksort($thresholds);

        return [
            'thresholds' => $thresholds,
            'cooling_off_hours_medium' => $this->integer('cooling_off_hours_medium'),
            'cooling_off_hours_high' => $this->integer('cooling_off_hours_high'),
            'allow_telegram_approvals' => $this->boolean('allow_telegram_approvals'),
            'escalate_pending_after_hours' => $this->integer('escalate_pending_after_hours'),
            'guard_trust_delay_hours' => $this->integer('guard_trust_delay_hours'),
            'require_second_factor' => $this->boolean('require_second_factor'),
            'solo_release_delay_hours' => $this->integer('solo_release_delay_hours'),
        ];
    }

    private function compare(string $other, bool $strict): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($other, $strict): void {
            $index = explode('.', $attribute)[1] ?? '';
            $base = $this->input('thresholds.'.$index.'.'.$other);

            if (! is_string($value) || $value === '' || ! is_string($base) || preg_match(self::DECIMAL, $value) !== 1 || preg_match(self::DECIMAL, $base) !== 1) {
                return;
            }

            $left = MoneyParser::toMinor($value);
            $right = MoneyParser::toMinor($base);

            if ($strict ? $left <= $right : $left < $right) {
                $fail((string) __('payflow.errors.threshold_order'));
            }
        };
    }
}
