<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Notifications\Enums\DigestChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\MemberNotificationSetting;
use App\Domain\Notifications\Routing\NotificationRouter;
use App\Enums\WorkspaceRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationSettingsController extends PayflowController
{
    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $role = $this->role();
        $setting = MemberNotificationSetting::query()->where('user_id', $user->id)->first();
        $defaults = self::roleDefaults($role);

        return Inertia::render('settings/Notifications', [
            'preferences' => $setting !== null ? [
                'telegram_events' => $setting->telegram_events,
                'email_events' => $setting->email_events,
                'digest_channel' => $setting->digest_channel->value,
                'quiet_hours_start' => $setting->quiet_hours_start !== null ? substr($setting->quiet_hours_start, 0, 5) : null,
                'quiet_hours_end' => $setting->quiet_hours_end !== null ? substr($setting->quiet_hours_end, 0, 5) : null,
            ] : $defaults,
            'available_events' => self::availableEvents($role),
            'forced_events' => NotificationRouter::forcedEvents($role),
            'telegram_linked' => $user->telegramAccount !== null,
            'role_defaults' => $defaults,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $events = self::availableEvents($this->role());
        $data = $request->validate([
            'telegram_events' => ['present', 'array'],
            'telegram_events.*' => [Rule::in($events)],
            'email_events' => ['present', 'array'],
            'email_events.*' => [Rule::in($events)],
            'digest_channel' => ['required', Rule::enum(DigestChannel::class)],
            'quiet_hours_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_end'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_start'],
        ]);

        $setting = MemberNotificationSetting::query()->firstOrNew(['workspace_id' => $this->workspace()->id, 'user_id' => $user->id]);
        $setting->forceFill([
            'workspace_id' => $this->workspace()->id,
            'user_id' => $user->id,
            'telegram_events' => array_values(array_unique(array_map('strval', (array) $data['telegram_events']))),
            'email_events' => array_values(array_unique(array_map('strval', (array) $data['email_events']))),
            'digest_channel' => DigestChannel::from((string) $data['digest_channel']),
            'quiet_hours_start' => $data['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $data['quiet_hours_end'] ?? null,
        ])->save();

        return $this->back('notifications_saved');
    }

    /** @return list<string> */
    public static function availableEvents(WorkspaceRole $role): array
    {
        $guardEvents = ['bank_change_requested', 'bank_change_high_risk', 'payout_pending_approval', 'payout_decided', 'payout_released', 'guard_settings_changed', 'guard_role_granted', 'receiving_details_change'];
        $arEvents = ['payment_received', 'reminder_failed', 'collections_escalation', 'reminders_opted_out', 'client_reported_paid'];
        $events = [];

        foreach (NotificationEvent::cases() as $event) {
            $value = $event->value;

            if (in_array($value, $arEvents, true) && ! $role->allows('invoices.view')) {
                continue;
            }

            if (in_array($value, $guardEvents, true) && ! ($role->allows('payouts.view') || $role->allows('vendors.view'))) {
                continue;
            }

            if ($value === 'payment_account_issue' && ! $role->allows('payments.configure')) {
                continue;
            }

            $events[] = $value;
        }

        return $events;
    }

    /** @return array{telegram_events: list<string>, email_events: list<string>, digest_channel: string, quiet_hours_start: null, quiet_hours_end: null} */
    public static function roleDefaults(WorkspaceRole $role): array
    {
        $events = array_values(array_intersect(NotificationRouter::defaultEvents($role), self::availableEvents($role)));

        return [
            'telegram_events' => $events,
            'email_events' => [],
            'digest_channel' => in_array('daily_digest', $events, true) ? 'telegram' : 'none',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ];
    }
}
