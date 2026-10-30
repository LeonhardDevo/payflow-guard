<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

final class NotificationRenderer
{
    public const array RISK_TEMPLATES = ['bank_change_requested', 'bank_change_high_risk', 'payout_pending_approval'];

    /**
     * @param  array<string, string|int>  $params
     * @param  list<string>  $lines
     * @return array{title: string, body: string}
     */
    public static function render(string $key, array $params = [], array $lines = [], ?string $titleKey = null): array
    {
        $escaped = self::escape($params);
        $title = (string) __('payflow.telegram.'.($titleKey ?? $key.'.title'), $escaped);
        $body = $lines !== [] ? $lines : [(string) __('payflow.telegram.'.$key.'.body', $escaped)];

        if (in_array($key, self::RISK_TEMPLATES, true)) {
            $body[] = (string) __('payflow.telegram.risk_disclaimer');
        }

        return ['title' => $title, 'body' => implode("\n", array_values(array_filter($body, fn (string $line): bool => $line !== '')))];
    }

    /** @param  array<string, string|int>  $params */
    public static function line(string $key, array $params = []): string
    {
        return (string) __('payflow.telegram.'.$key, self::escape($params));
    }

    /** @param  list<array{code: string, points: int, params: array<string, string|int>}>|null  $signals */
    public static function signals(?array $signals, int $limit = 4): string
    {
        $labels = [];

        foreach (array_slice($signals ?? [], 0, $limit) as $signal) {
            $labels[] = (string) __('payflow.signals.'.$signal['code'], array_map(fn (string|int $value): string => (string) $value, $signal['params']));
        }

        return implode(', ', $labels);
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array<string, string|int>
     */
    public static function escape(array $params): array
    {
        $result = [];

        foreach ($params as $name => $value) {
            $result[$name] = is_string($value) && ! str_starts_with($name, 'html_') ? e($value) : $value;
        }

        return $result;
    }
}
