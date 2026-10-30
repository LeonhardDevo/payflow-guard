<?php

declare(strict_types=1);

namespace App\Domain\Guard\Settings;

use App\Audit\Auditor;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSeverity;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class GuardSettingsService
{
    public const array FIELDS = [
        'cooling_off_hours_medium', 'cooling_off_hours_high', 'allow_telegram_approvals', 'escalate_pending_after_hours',
        'guard_trust_delay_hours', 'require_second_factor', 'solo_release_delay_hours',
    ];

    public function __construct(
        private Auditor $auditor,
        private GuardAlerts $alerts,
        private GuardNotifications $notifications,
    ) {}

    /**
     * @param  array{thresholds: array<string, array{auto_approve_below_minor: int, dual_approval_from_minor: int, auto_approve_cumulative_minor: int|null}>, cooling_off_hours_medium: int, cooling_off_hours_high: int, allow_telegram_approvals: bool, escalate_pending_after_hours: int, guard_trust_delay_hours: int, require_second_factor: bool, solo_release_delay_hours: int}  $data
     * @return array{changed: list<string>, loosened: bool}
     */
    public function update(Workspace $workspace, User $actor, array $data): array
    {
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $before = self::snapshot($settings);
        $after = [
            'thresholds' => $data['thresholds'],
            ...array_intersect_key($data, array_flip(self::FIELDS)),
        ];

        $changed = [];

        foreach ($after as $key => $value) {
            if (json_encode(self::normalize($before[$key] ?? null)) !== json_encode(self::normalize($value))) {
                $changed[] = $key;
            }
        }

        if ($changed === []) {
            return ['changed' => [], 'loosened' => false];
        }

        $loosened = self::isLoosening($before, $after);

        DB::transaction(function () use ($settings, $actor, $workspace, $before, $after, $changed, $loosened): void {
            $settings->forceFill([
                'approval_thresholds' => $after['thresholds'],
                ...array_intersect_key($after, array_flip(self::FIELDS)),
            ])->save();

            $diff = [];

            foreach ($changed as $key) {
                $diff[$key] = [$before[$key] ?? null, $after[$key]];
            }

            $entry = $this->auditor->record('payflow.settings.guard_updated', $settings, $diff, ['loosened' => $loosened], actor: $actor, workspace: $workspace, subjectLabel: $workspace->name);

            if (in_array('thresholds', $changed, true)) {
                $this->auditor->record('payflow.settings.thresholds_updated', $settings, ['thresholds' => [$before['thresholds'], $after['thresholds']]], actor: $actor, workspace: $workspace, subjectLabel: $workspace->name);
            }

            if (in_array('require_second_factor', $changed, true)) {
                $this->auditor->record('payflow.settings.second_factor_requirement_changed', $settings, ['require_second_factor' => [$before['require_second_factor'], $after['require_second_factor']]], actor: $actor, workspace: $workspace, subjectLabel: $workspace->name);
            }

            $alert = $this->alerts->ensure(
                $workspace->id,
                AlertType::GuardSettingsChanged,
                AlertSubjectType::Workspace,
                $workspace->id,
                'gs:'.$entry->id,
                ['user' => $actor->name, 'changes' => implode(',', $changed), 'loosened' => $loosened ? 1 : 0],
                $loosened ? AlertSeverity::Critical : AlertSeverity::Warning,
            );
            $labels = implode(', ', array_map(fn (string $key): string => (string) __('payflow.guard_settings.fields.'.$key), $changed));
            $this->notifications->guardSettingsChanged($workspace->id, $actor, $labels, $loosened, $alert);
        });

        return ['changed' => $changed, 'loosened' => $loosened];
    }

    /** @return array<string, mixed> */
    public static function snapshot(PayflowSettings $settings): array
    {
        return [
            'thresholds' => $settings->approval_thresholds,
            'cooling_off_hours_medium' => $settings->cooling_off_hours_medium,
            'cooling_off_hours_high' => $settings->cooling_off_hours_high,
            'allow_telegram_approvals' => $settings->allow_telegram_approvals,
            'escalate_pending_after_hours' => $settings->escalate_pending_after_hours,
            'guard_trust_delay_hours' => $settings->guard_trust_delay_hours,
            'require_second_factor' => $settings->require_second_factor,
            'solo_release_delay_hours' => $settings->solo_release_delay_hours,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function isLoosening(array $before, array $after): bool
    {
        foreach (['cooling_off_hours_medium', 'cooling_off_hours_high', 'guard_trust_delay_hours', 'solo_release_delay_hours'] as $key) {
            if ((int) $after[$key] < (int) $before[$key]) {
                return true;
            }
        }

        if (! (bool) $before['allow_telegram_approvals'] && (bool) $after['allow_telegram_approvals']) {
            return true;
        }

        if ((bool) $before['require_second_factor'] && ! (bool) $after['require_second_factor']) {
            return true;
        }

        $old = is_array($before['thresholds']) ? $before['thresholds'] : [];
        $new = is_array($after['thresholds']) ? $after['thresholds'] : [];

        foreach ($new as $currency => $row) {
            if (! isset($old[$currency]) || ! is_array($row)) {
                return true;
            }

            $previous = $old[$currency];
            $oldCap = $previous['auto_approve_cumulative_minor'] ?? 2 * (int) ($previous['auto_approve_below_minor'] ?? 0);
            $newCap = $row['auto_approve_cumulative_minor'] ?? 2 * (int) $row['auto_approve_below_minor'];

            if ((int) $row['auto_approve_below_minor'] > (int) ($previous['auto_approve_below_minor'] ?? 0)
                || (int) $row['dual_approval_from_minor'] > (int) ($previous['dual_approval_from_minor'] ?? 0)
                || (int) $newCap > (int) $oldCap) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
