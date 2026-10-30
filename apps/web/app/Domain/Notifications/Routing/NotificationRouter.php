<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Routing;

use App\Domain\Notifications\Email\DomainMailer;
use App\Domain\Notifications\Email\EmailSendV2;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\MemberNotificationSetting;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Locale\WithLocale;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Carbon\CarbonImmutable;
use Closure;

final readonly class NotificationRouter
{
    public const array DEFAULT_ROLES = [
        'payment_received' => ['owner', 'finance'],
        'reminder_failed' => ['finance'],
        'collections_escalation' => ['owner', 'finance'],
        'reminders_opted_out' => ['owner', 'finance'],
        'client_reported_paid' => ['finance'],
        'bank_change_requested' => ['owner', 'admin', 'approver', 'external_approver'],
        'bank_change_high_risk' => ['owner', 'admin', 'finance', 'approver', 'external_approver'],
        'payout_pending_approval' => ['owner', 'admin', 'approver', 'external_approver'],
        'payout_decided' => [],
        'payout_released' => ['owner'],
        'guard_settings_changed' => ['owner', 'admin', 'approver'],
        'guard_role_granted' => ['owner', 'admin', 'finance', 'approver', 'external_approver'],
        'receiving_details_change' => ['owner', 'admin', 'finance', 'approver'],
        'payment_account_issue' => ['owner', 'admin'],
        'daily_digest' => ['owner', 'finance', 'approver', 'member'],
    ];

    public const array OWN_CLIENT_EVENTS = ['payment_received', 'reminder_failed', 'collections_escalation', 'reminders_opted_out', 'client_reported_paid'];

    public function __construct(
        private Outbox $outbox,
        private DomainMailer $mailer,
        private Clock $clock,
    ) {}

    /**
     * @param  Closure(string): array{title: string, body: string}  $render
     * @param  Closure(string): list<array{text: string, url?: string, callback?: string}>|null  $buttons
     * @param  array{type: string, id: string|int}  $reference
     * @param  list<int>|null  $onlyUserIds
     * @param  list<int>|null  $audience
     * @param  list<int>  $exclude
     */
    public function send(
        int $workspaceId,
        NotificationEvent $event,
        Closure $render,
        array $reference,
        ?int $clientOwnerId = null,
        bool $forced = false,
        ?Closure $buttons = null,
        ?array $onlyUserIds = null,
        ?array $audience = null,
        array $exclude = [],
        bool $critical = false,
        ?int $alertId = null,
    ): int {
        $workspace = Workspace::query()->find($workspaceId);

        if ($workspace === null) {
            return 0;
        }

        $sent = 0;

        foreach ($this->recipients($workspace, $event, $clientOwnerId, $onlyUserIds, $forced) as [$user, $role]) {
            if (in_array($user->id, $exclude, true) || ($audience !== null && ! in_array($user->id, $audience, true))) {
                continue;
            }

            $channels = $this->channels($workspace, $user, $event, $forced);

            if ($channels === []) {
                continue;
            }

            $availableAt = $forced || $critical ? null : $this->quietUntil($workspace, $user);
            $locale = in_array($user->locale, ['en', 'ru'], true) ? $user->locale : 'en';
            $content = WithLocale::run($locale, fn (): array => $render($locale));
            $buttonList = $buttons !== null ? WithLocale::run($locale, fn (): array => $buttons($locale)) : [];

            if (in_array('telegram', $channels, true)) {
                $this->outbox->publish(Topics::TELEGRAM_NOTIFY, [
                    'user_id' => $user->id,
                    'locale' => $locale,
                    'text_html' => '<b>'.$content['title'].'</b>'."\n".$content['body'],
                    'buttons' => array_slice($buttonList, 0, 10),
                    'reference' => ['type' => $reference['type'], 'id' => (string) $reference['id']],
                ], $workspace->id, $availableAt);
                $sent++;
            }

            if (in_array('email', $channels, true)) {
                $this->mailer->publish($this->email($workspace, $user, $locale, $content, $buttonList, $alertId !== null ? ['type' => 'guard_alert', 'id' => $alertId] : ['type' => 'notification_'.$reference['type'], 'id' => $reference['id']]), $availableAt);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  list<int>|null  $onlyUserIds
     * @return list<array{0: User, 1: WorkspaceRole}>
     */
    public function recipients(Workspace $workspace, NotificationEvent $event, ?int $clientOwnerId = null, ?array $onlyUserIds = null, bool $forced = false): array
    {
        $members = $workspace->members()->whereNull('users.pseudonymised_at')->with('telegramAccount')->get();
        $result = [];

        foreach ($members as $user) {
            $role = $user->membership?->role;
            $role = $role instanceof WorkspaceRole ? $role : WorkspaceRole::tryFrom((string) $role);

            if ($role === null) {
                continue;
            }

            if ($onlyUserIds !== null) {
                if (in_array($user->id, $onlyUserIds, true)) {
                    $result[] = [$user, $role];
                }

                continue;
            }

            if (($forced && in_array($event->value, self::forcedEvents($role), true))
                || $this->wants($workspace, $user, $role, $event, $clientOwnerId)) {
                $result[] = [$user, $role];
            }
        }

        return $result;
    }

    /** @return list<string> */
    public static function defaultEvents(WorkspaceRole $role): array
    {
        $events = [];

        foreach (self::DEFAULT_ROLES as $event => $roles) {
            if (in_array($role->value, $roles, true)) {
                $events[] = $event;
            }
        }

        if ($role === WorkspaceRole::Member) {
            $events = [...$events, ...self::OWN_CLIENT_EVENTS];
        }

        if (in_array($role, [WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Finance], true)) {
            $events[] = 'payout_decided';
        }

        if ($role === WorkspaceRole::Finance) {
            $events[] = 'payout_released';
        }

        return array_values(array_unique($events));
    }

    /** @return list<string> */
    public static function forcedEvents(WorkspaceRole $role): array
    {
        return match ($role) {
            WorkspaceRole::Owner => ['bank_change_high_risk', 'guard_settings_changed', 'guard_role_granted', 'receiving_details_change', 'payment_account_issue'],
            WorkspaceRole::Admin, WorkspaceRole::Finance, WorkspaceRole::Approver, WorkspaceRole::ExternalApprover => ['guard_role_granted'],
            WorkspaceRole::Member => [],
        };
    }

    private function wants(Workspace $workspace, User $user, WorkspaceRole $role, NotificationEvent $event, ?int $clientOwnerId): bool
    {
        $isOwnClient = $clientOwnerId !== null && $clientOwnerId === $user->id;

        if (in_array($event->value, self::OWN_CLIENT_EVENTS, true)) {
            if (! $role->allows('invoices.view')) {
                return false;
            }

            if ($role === WorkspaceRole::Member) {
                return $isOwnClient;
            }

            if ($isOwnClient) {
                return true;
            }
        }

        $setting = $this->setting($workspace, $user);

        if ($setting !== null) {
            return in_array($event->value, $setting->telegram_events, true) || in_array($event->value, $setting->email_events, true);
        }

        return in_array($event->value, self::defaultEvents($role), true);
    }

    /** @return list<string> */
    private function channels(Workspace $workspace, User $user, NotificationEvent $event, bool $forced): array
    {
        $linked = $user->telegramAccount instanceof TelegramAccount;

        if ($forced) {
            return $linked ? ['telegram', 'email'] : ['email'];
        }

        $setting = $this->setting($workspace, $user);

        if ($setting === null) {
            return [$linked ? 'telegram' : 'email'];
        }

        $channels = [];

        if ($linked && in_array($event->value, $setting->telegram_events, true)) {
            $channels[] = 'telegram';
        }

        if (in_array($event->value, $setting->email_events, true)) {
            $channels[] = 'email';
        }

        if ($channels === [] && in_array($event->value, $setting->telegram_events, true)) {
            $channels[] = 'email';
        }

        return $channels;
    }

    private function setting(Workspace $workspace, User $user): ?MemberNotificationSetting
    {
        return MemberNotificationSetting::query()
            ->forWorkspace($workspace)
            ->where('user_id', $user->id)
            ->first();
    }

    private function quietUntil(Workspace $workspace, User $user): ?CarbonImmutable
    {
        $setting = $this->setting($workspace, $user);

        if ($setting === null || $setting->quiet_hours_start === null || $setting->quiet_hours_end === null) {
            return null;
        }

        $now = $this->clock->now();
        $timezone = WorkspaceClock::zone($workspace->timezone);
        $local = $now->setTimezone($timezone);
        $start = substr($setting->quiet_hours_start, 0, 5);
        $end = substr($setting->quiet_hours_end, 0, 5);
        $time = $local->format('H:i');
        $inside = $start <= $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);

        if (! $inside) {
            return null;
        }

        $endToday = CarbonImmutable::parse($local->toDateString().' '.$end, $timezone);

        return ($endToday->greaterThan($local) ? $endToday : $endToday->addDay())->utc();
    }

    /**
     * @param  array{title: string, body: string}  $content
     * @param  list<array{text: string, url?: string, callback?: string}>  $buttons
     * @param  array{type: string, id: string|int}  $reference
     */
    private function email(Workspace $workspace, User $user, string $locale, array $content, array $buttons, array $reference): EmailSendV2
    {
        $title = trim(html_entity_decode(strip_tags($content['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $bodyText = trim(html_entity_decode(strip_tags($content['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $links = array_values(array_filter($buttons, fn (array $button): bool => isset($button['url'])));
        $html = '<p><strong>'.e($title).'</strong></p>'.'<p>'.nl2br($content['body'], false).'</p>';
        $text = $title."\n\n".$bodyText;

        foreach ($links as $button) {
            $html .= '<p><a href="'.e((string) $button['url']).'">'.e($button['text']).'</a></p>';
            $text .= "\n".$button['text'].': '.$button['url'];
        }

        return new EmailSendV2(
            to: $user->email,
            subject: $title,
            html: $html,
            text: $text,
            reference: $reference,
            workspaceId: $workspace->id,
            toName: $user->name,
            fromName: (string) config('app.name'),
            locale: $locale,
        );
    }
}
