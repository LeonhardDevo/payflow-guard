<?php

declare(strict_types=1);

namespace App\Domain\Collections\Planning;

use App\Domain\Collections\Enums\ReminderKind;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Collections\Models\ReminderPolicyStep;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Collections\Tone\ToneSuggestion;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Notifications\Routing\ReceivablesNotifications;
use App\Domain\Receivables\Clients\ClientTerms;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class ReminderPlanner
{
    public function __construct(
        private GuardAlerts $alerts,
        private ReceivablesNotifications $notifications,
    ) {}

    /** @return array{reminders: list<array{id: int, scheduled_for: string}>, created: int, superseded: int} */
    public function plan(Workspace $workspace, CarbonImmutable $now): array
    {
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
        $created = 0;
        $superseded = 0;

        $this->liftExpiredPauses($workspace, $today);

        Invoice::query()
            ->forWorkspace($workspace)
            ->whereIn('status', Invoice::OPEN_STATUSES)
            ->whereNotNull('sent_at')
            ->with('client')
            ->chunkById((int) config('payflow.planner.chunk', 200), function ($invoices) use ($workspace, $settings, $today, $now, &$created, &$superseded): void {
                foreach ($invoices as $invoice) {
                    [$c, $s] = $this->planInvoice($workspace, $settings, $invoice, $today, $now);
                    $created += $c;
                    $superseded += $s;
                }
            });

        $horizon = $now->addHours((int) config('payflow.planner.lookahead_hours', 26));
        $due = array_values(Reminder::query()
            ->forWorkspace($workspace)
            ->where('status', ReminderStatus::Scheduled->value)
            ->where('scheduled_for', '<=', $horizon)
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get(['id', 'scheduled_for'])
            ->map(fn (Reminder $reminder): array => [
                'id' => $reminder->id,
                'scheduled_for' => $reminder->scheduled_for->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->all());

        return ['reminders' => $due, 'created' => $created, 'superseded' => $superseded];
    }

    /** @return array{0: int, 1: int} */
    private function planInvoice(Workspace $workspace, PayflowSettings $settings, Invoice $invoice, string $today, CarbonImmutable $now): array
    {
        $client = $invoice->client;
        $policy = ClientTerms::policy($client);

        if ($policy === null) {
            Log::error('payflow.planner.policy_missing', ['workspace_id' => $workspace->id, 'invoice_id' => $invoice->id]);

            return [0, 0];
        }

        if (ReminderEligibility::check($invoice, $today) !== null) {
            return [0, 0];
        }

        $daysOverdue = max(0, BusinessDate::diff($invoice->dueDate(), $today));

        if ($invoice->reminders_sent_count >= $policy->max_reminders) {
            $this->escalate($invoice, $today);

            return [0, 0];
        }

        if ($daysOverdue >= $policy->escalate_after_days) {
            $this->escalate($invoice, $today);

            if ($invoice->reminders_sent_count === 0) {
                return [0, 0];
            }
        }

        $lookahead = BusinessDate::addDays($today, 1);
        $planned = Reminder::query()
            ->forWorkspace($workspace)
            ->where('invoice_id', $invoice->id)
            ->where('kind', ReminderKind::Scheduled->value)
            ->whereNotNull('policy_step_id')
            ->pluck('policy_step_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $new = $policy->steps
            ->filter(fn (ReminderPolicyStep $step): bool => BusinessDate::addDays($invoice->dueDate(), $step->offset_days) <= $lookahead)
            ->reject(fn (ReminderPolicyStep $step): bool => in_array($step->id, $planned, true))
            ->sortBy('offset_days')
            ->values();

        if ($new->isEmpty()) {
            return [0, 0];
        }

        $latest = $new->last();
        $superseded = 0;

        foreach ($new as $step) {
            if ($step->id === $latest->id) {
                continue;
            }

            $superseded += $this->insert($invoice, $step, [
                'is_first_contact' => false,
                'tone' => Tone::Friendly,
                'tone_reasons' => [],
                'scheduled_for' => $now,
                'status' => ReminderStatus::Skipped,
                'skip_reason' => ReminderSkipReason::Superseded,
            ]);
        }

        $first = $this->isFirstContact($invoice);
        $tz = ClientTerms::sendTimezone($client, $workspace);
        $spread = SendSlot::spread($invoice->public_token);
        $target = BusinessDate::max(BusinessDate::addDays($invoice->dueDate(), $latest->offset_days), $today);
        $slot = SendSlot::next($target, $settings->windowStart(), $settings->windowEnd(), $settings->business_days_only, $tz, $now, $spread);

        if ($invoice->last_reminder_at !== null) {
            $earliest = CarbonImmutable::parse($invoice->last_reminder_at)->addDays($policy->min_interval_days);

            if ($slot->lessThan($earliest)) {
                $date = BusinessDate::addDays(WorkspaceClock::localDate($invoice->last_reminder_at, $workspace->timezone), $policy->min_interval_days);
                $slot = SendSlot::next($date, $settings->windowStart(), $settings->windowEnd(), $settings->business_days_only, $tz, $earliest->greaterThan($now) ? $earliest : $now, $spread);
            }
        }

        if ($first) {
            $slot = $this->throttleFirstContact($slot, $workspace, $settings, $tz, $now, $spread);
        }

        $tone = self::plannedTone($invoice, $latest, WorkspaceClock::localDate($slot, $workspace->timezone), $first);

        $created = $this->insert($invoice, $latest, [
            'is_first_contact' => $first,
            'tone' => $tone->tone,
            'tone_reasons' => $tone->reasons,
            'scheduled_for' => $slot,
            'status' => ReminderStatus::Scheduled,
            'skip_reason' => null,
        ]);

        return [$created, $superseded];
    }

    public static function plannedTone(Invoice $invoice, ReminderPolicyStep $step, string $date, bool $firstContact): ToneSuggestion
    {
        $suggestion = ToneContext::suggest($invoice, $date, $step->tone);

        if ($firstContact && $invoice->first_contact_confirmed_at === null) {
            return $suggestion->withCap(Tone::Neutral, 'first_contact_cap');
        }

        return $suggestion;
    }

    public function isFirstContact(Invoice $invoice): bool
    {
        if ($invoice->reminders_sent_count > 0) {
            return false;
        }

        return ! Reminder::query()
            ->forWorkspace($invoice->workspace_id)
            ->where('invoice_id', $invoice->id)
            ->where('kind', ReminderKind::Scheduled->value)
            ->whereIn('status', [ReminderStatus::Queued->value, ReminderStatus::Deferred->value, ReminderStatus::Sent->value])
            ->exists();
    }

    public function throttleFirstContact(CarbonImmutable $slot, Workspace $workspace, PayflowSettings $settings, string $tz, CarbonImmutable $now, int $spread): CarbonImmutable
    {
        $zone = WorkspaceClock::zone($workspace->timezone);

        for ($guard = 0; $guard < 2000; $guard++) {
            $local = $slot->setTimezone($zone);
            $hourStart = $local->setTime((int) $local->format('H'), 0)->utc();
            $hourEnd = $hourStart->addHour();

            $count = Reminder::query()
                ->forWorkspace($workspace)
                ->where('is_first_contact', true)
                ->where('status', ReminderStatus::Scheduled->value)
                ->where('scheduled_for', '>=', $hourStart)
                ->where('scheduled_for', '<', $hourEnd)
                ->count();

            if ($count < $settings->first_contact_max_per_hour) {
                return $slot;
            }

            $from = $now->greaterThan($hourEnd) ? $now : $hourEnd;
            $slot = SendSlot::next(
                WorkspaceClock::localDate($hourEnd, $tz),
                $settings->windowStart(),
                $settings->windowEnd(),
                $settings->business_days_only,
                $tz,
                $from,
                $spread,
            );
        }

        return $slot;
    }

    /** @param  array{is_first_contact: bool, tone: Tone, tone_reasons: list<array{code: string, params: array<string, string|int|float>}>, scheduled_for: CarbonImmutable, status: ReminderStatus, skip_reason: ReminderSkipReason|null}  $attributes */
    private function insert(Invoice $invoice, ReminderPolicyStep $step, array $attributes): int
    {
        $timestamp = now();

        return DB::table('reminders')->insertOrIgnore([
            'workspace_id' => $invoice->workspace_id,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'policy_step_id' => $step->id,
            'kind' => ReminderKind::Scheduled->value,
            'step_offset_days' => $step->offset_days,
            'is_first_contact' => $attributes['is_first_contact'],
            'tone' => $attributes['tone']->value,
            'tone_reasons' => json_encode($attributes['tone_reasons'], JSON_THROW_ON_ERROR),
            'locale' => $invoice->locale,
            'scheduled_for' => $attributes['scheduled_for']->utc(),
            'status' => $attributes['status']->value,
            'skip_reason' => $attributes['skip_reason']?->value,
            'cc_emails' => '[]',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function escalate(Invoice $invoice, string $today): void
    {
        $alert = $this->alerts->ensure(
            $invoice->workspace_id,
            AlertType::CollectionsEscalation,
            AlertSubjectType::Invoice,
            $invoice->id,
            'esc:'.$invoice->id,
            ['number' => $invoice->label(), 'client' => $invoice->client->name, 'days' => max(0, BusinessDate::diff($invoice->dueDate(), $today))],
        );

        if ($alert->wasRecentlyCreated) {
            $this->notifications->collectionsEscalation($invoice, $today);
        }
    }

    private function liftExpiredPauses(Workspace $workspace, string $today): void
    {
        Invoice::query()->forWorkspace($workspace)
            ->where('reminders_paused', true)
            ->whereNotNull('reminders_paused_until')
            ->where('reminders_paused_until', '<', $today)
            ->update(['reminders_paused' => false, 'reminders_paused_until' => null, 'reminders_pause_source' => null]);

        Client::query()->forWorkspace($workspace)
            ->where('reminders_paused', true)
            ->whereNotNull('reminders_paused_until')
            ->where('reminders_paused_until', '<', $today)
            ->update(['reminders_paused' => false, 'reminders_paused_until' => null, 'reminders_pause_source' => null]);
    }

    public static function policyFor(Invoice $invoice): ?ReminderPolicy
    {
        return ClientTerms::policy($invoice->client);
    }
}
