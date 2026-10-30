<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Models\Workspace;
use Illuminate\Contracts\Config\Repository as Config;

final readonly class RetentionPolicy
{
    public function __construct(private Config $config) {}

    public function default(string $key): int
    {
        return (int) $this->config->get("retention.defaults.{$key}", 0);
    }

    /** @return array<string, array{min: int, max: int}> */
    public function overridable(): array
    {
        $bounds = [];

        foreach ((array) $this->config->get('retention.overridable', []) as $key => $range) {
            if (is_string($key) && is_array($range)) {
                $bounds[$key] = ['min' => (int) ($range['min'] ?? 1), 'max' => (int) ($range['max'] ?? 3650)];
            }
        }

        return $bounds;
    }

    /** @return array{min: int, max: int}|null */
    public function bounds(string $key, ?Workspace $workspace): ?array
    {
        $range = $this->overridable()[$key] ?? null;

        if ($range === null) {
            return null;
        }

        if ($workspace !== null) {
            $cap = $this->config->get('retention.plan_max.'.$workspace->planKey().'.'.$key);

            if (is_numeric($cap)) {
                $range['max'] = max($range['min'], min($range['max'], (int) $cap));
            }
        }

        return $range;
    }

    public function days(string $key, ?Workspace $workspace = null): int
    {
        $default = $this->default($key);
        $override = $workspace?->retention[$key] ?? null;
        $bounds = $this->bounds($key, $workspace);

        if ($bounds === null || ! is_numeric($override)) {
            return $bounds !== null ? min($default, $bounds['max']) : $default;
        }

        return max($bounds['min'], min($bounds['max'], (int) $override));
    }

    /** @return array<string, int> */
    public function forWorkspace(?Workspace $workspace): array
    {
        $values = [];

        foreach (array_keys($this->overridable()) as $key) {
            $values[$key] = $this->days($key, $workspace);
        }

        return $values;
    }

    public function webhookEventsDays(): int
    {
        return $this->default('webhook_events');
    }
}
