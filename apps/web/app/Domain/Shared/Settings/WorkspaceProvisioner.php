<?php

declare(strict_types=1);

namespace App\Domain\Shared\Settings;

use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Collections\Models\ReminderPolicyStep;
use App\Domain\Collections\Templates\DefaultTemplates;
use App\Domain\Shared\Models\PayflowSettings;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class WorkspaceProvisioner
{
    public const array STANDARD_STEPS = [
        [-3, 'auto', false, false],
        [0, 'auto', false, false],
        [4, 'auto', false, false],
        [9, 'auto', false, false],
        [14, 'auto', true, false],
        [21, 'auto', false, true],
        [30, 'auto', true, true],
        [45, 'final', true, true],
    ];

    public static function settingsFor(Workspace|int $workspace): PayflowSettings
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        $settings = PayflowSettings::query()->forWorkspace($workspaceId)->first();

        if ($settings !== null && $settings->default_reminder_policy_id !== null) {
            return $settings;
        }

        $model = $workspace instanceof Workspace ? $workspace : Workspace::query()->findOrFail($workspaceId);

        return self::provision($model);
    }

    public static function provision(Workspace $workspace): PayflowSettings
    {
        return DB::transaction(function () use ($workspace): PayflowSettings {
            $settings = PayflowSettings::query()->forWorkspace($workspace)->lockForUpdate()->first();

            if ($settings === null) {
                $settings = new PayflowSettings;
                $settings->forceFill(['workspace_id' => $workspace->id])->save();
            }

            if ($settings->default_reminder_policy_id === null) {
                $policy = ReminderPolicy::query()->forWorkspace($workspace)->where('is_default', true)->first()
                    ?? self::createStandardPolicy($workspace);
                $settings->forceFill(['default_reminder_policy_id' => $policy->id])->save();
            }

            DefaultTemplates::ensureFor($workspace);

            return $settings->refresh();
        });
    }

    public static function createStandardPolicy(Workspace $workspace, bool $default = true): ReminderPolicy
    {
        $locale = in_array($workspace->locale, DefaultTemplates::LOCALES, true) ? $workspace->locale : 'en';
        $policy = new ReminderPolicy;
        $policy->forceFill([
            'workspace_id' => $workspace->id,
            'name' => (string) trans('payflow.policies.standard.name', [], $locale),
            'description' => (string) trans('payflow.policies.standard.description', [], $locale),
            'is_default' => $default,
            'max_reminders' => 8,
            'min_interval_days' => 3,
            'escalate_after_days' => 45,
        ])->save();

        foreach (self::STANDARD_STEPS as $position => [$offset, $tone, $lateFee, $notify]) {
            $step = new ReminderPolicyStep;
            $step->forceFill([
                'workspace_id' => $workspace->id,
                'policy_id' => $policy->id,
                'position' => $position + 1,
                'offset_days' => $offset,
                'tone' => StepTone::from($tone),
                'attach_pdf' => true,
                'include_payment_link' => true,
                'include_late_fee_notice' => $lateFee,
                'notify_owner' => $notify,
            ])->save();
        }

        return $policy;
    }
}
