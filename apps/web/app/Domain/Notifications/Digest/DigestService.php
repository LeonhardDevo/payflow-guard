<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Digest;

use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Notifications\Enums\DigestChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\DigestRun;
use App\Domain\Notifications\Models\MemberNotificationSetting;
use App\Domain\Notifications\Routing\NotificationRouter;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class DigestService
{
    public function __construct(private NotificationRouter $router) {}

    /** @return array{workspaces: int, messages: int} */
    public function run(CarbonImmutable $now): array
    {
        $summary = ['workspaces' => 0, 'messages' => 0];

        Workspace::query()->orderBy('id')->each(function (Workspace $workspace) use ($now, &$summary): void {
            $settings = WorkspaceProvisioner::settingsFor($workspace);
            $local = $now->setTimezone(WorkspaceClock::zone($workspace->timezone));

            if (! $settings->digest_enabled || (int) $local->format('G') !== $settings->digest_hour) {
                return;
            }

            $run = $this->claim($workspace, $local->toDateString(), $now);

            if ($run === null) {
                return;
            }

            $messages = $this->send($workspace, $local->toDateString(), $now);
            $run->forceFill(['recipients' => $messages])->save();
            $summary['workspaces']++;
            $summary['messages'] += $messages;
        });

        return $summary;
    }

    private function claim(Workspace $workspace, string $date, CarbonImmutable $now): ?DigestRun
    {
        $inserted = DB::table('digest_runs')->insertOrIgnore([
            'workspace_id' => $workspace->id,
            'local_date' => $date,
            'kind' => 'daily',
            'recipients' => 0,
            'sent_at' => $now,
        ]);

        if ($inserted === 0) {
            return null;
        }

        return DigestRun::query()->forWorkspace($workspace)->where('local_date', $date)->where('kind', 'daily')->first();
    }

    public function send(Workspace $workspace, string $today, CarbonImmutable $now): int
    {
        $sent = 0;

        foreach ($workspace->members()->whereNull('users.pseudonymised_at')->with('telegramAccount')->get() as $user) {
            $role = $user->membership?->role;
            $role = $role instanceof WorkspaceRole ? $role : WorkspaceRole::tryFrom((string) $role);

            if ($role === null || ! $this->wantsDigest($workspace, $user, $role)) {
                continue;
            }

            $sent += $this->router->send(
                $workspace->id,
                NotificationEvent::DailyDigest,
                fn (string $locale): array => $this->content($workspace, $user, $role, $today, $now, $locale),
                ['type' => 'digest', 'id' => $workspace->id.'-'.$today],
                buttons: fn (): array => [['text' => (string) __('payflow.telegram.open_dashboard'), 'url' => route('dashboard')]],
                onlyUserIds: [$user->id],
            );
        }

        return $sent;
    }

    private function wantsDigest(Workspace $workspace, User $user, WorkspaceRole $role): bool
    {
        $setting = MemberNotificationSetting::query()->forWorkspace($workspace)->where('user_id', $user->id)->first();

        if ($setting !== null) {
            return $setting->digest_channel !== DigestChannel::None;
        }

        return in_array(NotificationEvent::DailyDigest->value, NotificationRouter::defaultEvents($role), true);
    }

    /** @return array{title: string, body: string} */
    public function content(Workspace $workspace, User $user, WorkspaceRole $role, string $today, CarbonImmutable $now, string $locale): array
    {
        $timezone = WorkspaceClock::zone($workspace->timezone);
        $ownOnly = $role === WorkspaceRole::Member;
        $invoices = Invoice::query()->forWorkspace($workspace)->with('client')
            ->whereIn('status', Invoice::OUTSTANDING_STATUSES)
            ->when($ownOnly, fn ($query) => $query->whereHas('client', fn ($client) => $client->where('owner_user_id', $user->id)))
            ->get()
            ->toBase();
        $overdue = $invoices->filter(fn (Invoice $invoice): bool => $invoice->isOpen() && $invoice->dueDate() < $today);
        $weekEnd = BusinessDate::addDays($today, 6);
        $dueWeek = $invoices->filter(fn (Invoice $invoice): bool => $invoice->isOpen() && $invoice->dueDate() >= $today && $invoice->dueDate() <= $weekEnd);
        $yesterdayStart = BusinessDate::toUtc(BusinessDate::addDays($today, -1), '00:00', $timezone);
        $todayStart = BusinessDate::toUtc($today, '00:00', $timezone);
        $paid = Payment::query()->forWorkspace($workspace)
            ->whereIn('status', Payment::COUNTED_STATUSES)
            ->where('paid_at', '>=', $yesterdayStart)
            ->where('paid_at', '<', $todayStart)
            ->when($ownOnly, fn ($query) => $query->whereHas('client', fn ($client) => $client->where('owner_user_id', $user->id)))
            ->get();
        $reminders = Reminder::query()->forWorkspace($workspace)
            ->where('status', ReminderStatus::Scheduled->value)
            ->where('scheduled_for', '>=', $todayStart)
            ->where('scheduled_for', '<', BusinessDate::toUtc(BusinessDate::addDays($today, 1), '00:00', $timezone))
            ->count();

        $money = fn (Collection $items): string => self::joined($items->groupBy('currency')->map(fn (Collection $group): int => (int) $group->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue())))->all(), $locale);
        $lines = [
            (string) __('payflow.telegram.daily_digest.outstanding', [
                'outstanding' => $money($invoices),
                'overdue' => $money($overdue),
                'overdue_count' => trans_choice('payflow.telegram.daily_digest.invoices', $overdue->count(), ['count' => $overdue->count()]),
            ]),
            (string) __('payflow.telegram.daily_digest.paid_yesterday', [
                'amount' => self::joined($paid->groupBy('currency')->map(fn ($group): int => (int) $group->sum(fn (Payment $payment): int => $payment->amount_minor - $payment->refunded_minor))->all(), $locale),
            ]),
            (string) __('payflow.telegram.daily_digest.due_week', [
                'due_count' => trans_choice('payflow.telegram.daily_digest.invoices', $dueWeek->count(), ['count' => $dueWeek->count()]),
                'due_amount' => $money($dueWeek),
            ]),
            (string) __('payflow.telegram.daily_digest.reminders_today', ['count' => $reminders]),
        ];

        if ($role->allows('payouts.view') || $role->allows('vendors.view')) {
            $decided = PayoutApproval::query()->forWorkspace($workspace)->where('user_id', $user->id)->pluck('payout_request_id')->all();
            $approvals = $role->allows('payouts.approve') ? PayoutRequest::query()->forWorkspace($workspace)
                ->where('status', PayoutStatus::PendingApproval->value)->where('requested_by', '!=', $user->id)->whereNotIn('id', $decided)->count() : 0;
            $changes = VendorBankAccount::query()->forWorkspace($workspace)->where('status', BankAccountStatus::PendingVerification->value)->count();
            $lines[] = (string) __('payflow.telegram.daily_digest.guard', ['approvals' => $approvals, 'changes' => $changes]);
        }

        $top = $overdue->sortBy(fn (Invoice $invoice): string => $invoice->dueDate())->take(3)->values();

        if ($top->isNotEmpty()) {
            $lines[] = (string) __('payflow.telegram.daily_digest.top_overdue');

            foreach ($top as $index => $invoice) {
                $lines[] = (string) __('payflow.telegram.daily_digest.top_line', [
                    'position' => $index + 1,
                    'number' => e($invoice->label()),
                    'client' => e($invoice->client->name),
                    'days' => BusinessDate::diff($invoice->dueDate(), $today),
                    'amount' => MoneyData::formatted($invoice->balanceDue(), $invoice->currency, $locale)['formatted'],
                ]);
            }
        }

        $date = CarbonImmutable::parse($today)->locale($locale);

        return [
            'title' => (string) __('payflow.telegram.daily_digest.title', [
                'workspace' => e($workspace->name),
                'date' => $date instanceof CarbonImmutable ? $date->isoFormat('LL') : $today,
            ]),
            'body' => implode("\n", $lines),
        ];
    }

    /** @param  array<string, int>  $amounts */
    private static function joined(array $amounts, string $locale): string
    {
        $amounts = array_filter($amounts, fn (int $amount): bool => $amount !== 0);

        return $amounts === [] ? (string) __('payflow.telegram.daily_digest.nothing') : MoneyData::joined($amounts, $locale);
    }
}
