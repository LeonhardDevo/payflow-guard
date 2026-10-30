<?php

declare(strict_types=1);

namespace App\Domain\Collections\Settings;

use App\Audit\Auditor;
use App\Domain\Collections\Dispatch\ReminderCanceller;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Models\MessageTemplate;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Collections\Models\ReminderPolicyStep;
use App\Domain\Collections\Templates\DefaultTemplates;
use App\Domain\Collections\Templates\TemplateRenderer;
use App\Domain\Receivables\Models\Client;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class CollectionsSettingsService
{
    public const array FIELDS = [
        'invoice_prefix', 'default_payment_terms_days', 'default_reminder_policy_id', 'send_window_start', 'send_window_end', 'business_days_only',
        'first_contact_max_per_hour', 'sender_display_name', 'reply_to_email', 'privacy_contact_email', 'track_invoice_views', 'late_fee_type',
        'late_fee_rate_bp', 'late_fee_fixed_minor', 'late_fee_grace_days', 'late_fee_cap_bp', 'company_legal_name', 'company_address',
        'company_tax_id', 'company_email', 'company_phone', 'digest_enabled', 'digest_hour',
    ];

    public function __construct(
        private ReminderCanceller $canceller,
        private Auditor $auditor,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function update(Workspace $workspace, User $actor, array $data): PayflowSettings
    {
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $fields = array_intersect_key($data, array_flip(self::FIELDS));

        if (isset($fields['default_reminder_policy_id'])) {
            ReminderPolicy::query()->forWorkspace($workspace)->findOrFail($fields['default_reminder_policy_id']);
        }

        return DB::transaction(function () use ($workspace, $actor, $settings, $fields): PayflowSettings {
            $before = $settings->only(array_keys($fields));
            $policyBefore = $settings->default_reminder_policy_id;
            $settings->forceFill($fields)->save();

            if ($settings->default_reminder_policy_id !== $policyBefore && $settings->default_reminder_policy_id !== null) {
                ReminderPolicy::query()->forWorkspace($workspace)->where('is_default', true)->update(['is_default' => false]);
                ReminderPolicy::query()->forWorkspace($workspace)->whereKey($settings->default_reminder_policy_id)->update(['is_default' => true]);
                $this->cancelForInheritingClients($workspace);
            }

            $changes = Auditor::diff(self::comparable($before), self::comparable($settings->only(array_keys($fields))));

            if ($changes !== []) {
                $this->auditor->record('payflow.settings.collections_updated', $settings, $changes, actor: $actor, workspace: $workspace);
            }

            return $settings->refresh();
        });
    }

    /** @param  array{name: string, description: string|null, is_default: bool, max_reminders: int, min_interval_days: int, escalate_after_days: int, steps: list<array{offset_days: int, tone: string, attach_pdf: bool, include_payment_link: bool, include_late_fee_notice: bool, notify_owner: bool}>}  $data */
    public function savePolicy(Workspace $workspace, User $actor, array $data, ?ReminderPolicy $policy = null): ReminderPolicy
    {
        return DB::transaction(function () use ($workspace, $actor, $data, $policy): ReminderPolicy {
            $creating = $policy === null;
            $policy ??= new ReminderPolicy;
            $policy->forceFill([
                'workspace_id' => $workspace->id,
                'name' => $data['name'],
                'description' => $data['description'],
                'max_reminders' => $data['max_reminders'],
                'min_interval_days' => $data['min_interval_days'],
                'escalate_after_days' => $data['escalate_after_days'],
            ])->save();

            $steps = $data['steps'];
            usort($steps, fn (array $a, array $b): int => $a['offset_days'] <=> $b['offset_days']);
            $existing = ReminderPolicyStep::query()->forWorkspace($workspace)->where('policy_id', $policy->id)->get()->keyBy('offset_days');
            $offsets = array_map(fn (array $step): int => $step['offset_days'], $steps);

            foreach ($existing as $offset => $model) {
                if (! in_array((int) $offset, $offsets, true)) {
                    $model->delete();
                } else {
                    $model->forceFill(['position' => $model->position + 1000])->save();
                }
            }

            foreach ($steps as $index => $step) {
                $model = $existing->get($step['offset_days']) ?? new ReminderPolicyStep;
                $model->forceFill([
                    'workspace_id' => $workspace->id,
                    'policy_id' => $policy->id,
                    'position' => $index + 1,
                    'offset_days' => $step['offset_days'],
                    'tone' => StepTone::from($step['tone']),
                    'attach_pdf' => $step['attach_pdf'],
                    'include_payment_link' => $step['include_payment_link'],
                    'include_late_fee_notice' => $step['include_late_fee_notice'],
                    'notify_owner' => $step['notify_owner'],
                ])->save();
            }

            if ($data['is_default'] && ! $policy->is_default) {
                ReminderPolicy::query()->forWorkspace($workspace)->where('is_default', true)->whereKeyNot($policy->id)->update(['is_default' => false]);
                $policy->forceFill(['is_default' => true])->save();
                WorkspaceProvisioner::settingsFor($workspace)->forceFill(['default_reminder_policy_id' => $policy->id])->save();
            }

            if (! $creating) {
                $this->cancelForPolicy($workspace, $policy);
            }

            $this->auditor->record($creating ? 'payflow.policy.created' : 'payflow.policy.updated', $policy, metadata: ['steps' => count($steps)], actor: $actor, workspace: $workspace, subjectLabel: $policy->name);

            return $policy->refresh()->load('steps');
        });
    }

    public function deletePolicy(Workspace $workspace, User $actor, ReminderPolicy $policy): void
    {
        $settings = WorkspaceProvisioner::settingsFor($workspace);

        if ($policy->is_default || $settings->default_reminder_policy_id === $policy->id) {
            throw new PayflowException('default_policy', 409);
        }

        DB::transaction(function () use ($workspace, $actor, $policy): void {
            $clients = Client::query()->forWorkspace($workspace)->where('reminder_policy_id', $policy->id)->get();

            foreach ($clients as $client) {
                $client->forceFill(['reminder_policy_id' => null])->save();
                $this->canceller->forClient($client, ReminderSkipReason::PolicyChanged);
            }

            $policy->delete();
            $this->auditor->record('payflow.policy.deleted', $policy, actor: $actor, workspace: $workspace, subjectLabel: $policy->name);
        });
    }

    public function updateTemplate(MessageTemplate $template, User $actor, string $subject, string $body): MessageTemplate
    {
        $unknown = TemplateRenderer::unknownVariables($subject, $body);

        if ($unknown !== []) {
            throw new PayflowException('unknown_variables', 422, ['variables' => implode(', ', $unknown)]);
        }

        $template->forceFill(['subject' => $subject, 'body_markdown' => $body, 'is_customized' => true, 'updated_by' => $actor->id])->save();
        $this->auditor->record('payflow.template.updated', $template, metadata: ['key' => $template->key->value, 'locale' => $template->locale], actor: $actor, workspace: $template->workspace_id);

        return $template;
    }

    public function resetTemplate(MessageTemplate $template, User $actor): MessageTemplate
    {
        $text = DefaultTemplates::text($template->key, $template->locale);
        $template->forceFill(['subject' => $text['subject'], 'body_markdown' => $text['body'], 'is_customized' => false, 'updated_by' => $actor->id])->save();
        $this->auditor->record('payflow.template.reset', $template, metadata: ['key' => $template->key->value, 'locale' => $template->locale], actor: $actor, workspace: $template->workspace_id);

        return $template;
    }

    private function cancelForPolicy(Workspace $workspace, ReminderPolicy $policy): void
    {
        $stepIds = ReminderPolicyStep::query()->forWorkspace($workspace)->where('policy_id', $policy->id)->pluck('id')->all();

        Reminder::query()->forWorkspace($workspace)
            ->where('status', ReminderStatus::Scheduled->value)
            ->where(fn ($query) => $query->whereNull('policy_step_id')->orWhereNotIn('policy_step_id', $stepIds))
            ->whereIn('client_id', Client::query()->forWorkspace($workspace)->where('reminder_policy_id', $policy->id)->select('id'))
            ->update(['status' => ReminderStatus::Canceled->value, 'skip_reason' => ReminderSkipReason::PolicyChanged->value, 'policy_step_id' => null]);
    }

    private function cancelForInheritingClients(Workspace $workspace): void
    {
        Reminder::query()->forWorkspace($workspace)
            ->where('status', ReminderStatus::Scheduled->value)
            ->whereIn('client_id', Client::query()->forWorkspace($workspace)->whereNull('reminder_policy_id')->select('id'))
            ->update(['status' => ReminderStatus::Canceled->value, 'skip_reason' => ReminderSkipReason::PolicyChanged->value, 'policy_step_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function comparable(array $values): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value, $values);
    }
}
