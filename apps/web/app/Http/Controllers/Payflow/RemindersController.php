<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Domain\Analytics\ReceivablesAnalytics;
use App\Domain\Collections\Dispatch\ManualReminders;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Shared\Time\Clock;
use App\Http\Resources\Payflow\Paginated;
use App\Http\Resources\Payflow\ReminderResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class RemindersController extends PayflowController
{
    public const array TABS = [
        'upcoming' => ['scheduled'],
        'sent' => ['queued', 'deferred', 'sent'],
        'failed' => ['failed'],
        'skipped' => ['skipped', 'canceled'],
    ];

    public function index(Request $request, Clock $clock): Response
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'upcoming';
        $tone = Tone::tryFrom((string) $request->query('tone', ''));
        $q = trim((string) $request->query('q', ''));
        $today = $this->today();
        $now = $clock->now();

        $base = fn (): Builder => Reminder::query()
            ->when($tone !== null, fn (Builder $query) => $query->where('tone', $tone?->value))
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereHas('invoice', fn (Builder $invoice) => $invoice->whereRaw('lower(coalesce(number, \'\')) like ?', ['%'.mb_strtolower($q).'%']))
                ->orWhereHas('client', fn (Builder $client) => $client->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%']))));

        $query = $base()->with(['invoice', 'client', 'creator'])->whereIn('status', self::TABS[$tab]);
        $tab === 'upcoming' ? $query->orderBy('scheduled_for') : $query->orderByDesc('updated_at');
        $paginator = $query->orderByDesc('id')->paginate(25)->withQueryString();

        $counts = [];

        foreach (self::TABS as $key => $statuses) {
            $counts[$key] = $base()->whereIn('status', $statuses)->count();
        }

        $effectiveness = (new ReceivablesAnalytics($this->workspace(), $now))->toneEffectiveness();
        $sent = array_sum(array_column($effectiveness, 'sent'));
        $paid = array_sum(array_column($effectiveness, 'paid_within_7d'));

        return Inertia::render('reminders/Index', [
            'tab' => $tab,
            'reminders' => Paginated::of($paginator, collect($paginator->items())->map(fn (Reminder $reminder): array => ReminderResource::listItem($reminder, $today))->values()->all()),
            'counts' => $counts,
            'stats' => [
                'sent_30d' => Reminder::query()->where('status', ReminderStatus::Sent->value)->where('sent_at', '>=', $now->subDays(30))->count(),
                'paid_within_7d_rate' => $sent > 0 ? round($paid / $sent, 4) : null,
                'failed_7d' => Reminder::query()->where('status', ReminderStatus::Failed->value)->where('failed_at', '>=', $now->subDays(7))->count(),
            ],
            'filters' => ['tone' => $tone?->value, 'q' => $q !== '' ? $q : null],
        ]);
    }

    public function show(Reminder $reminder): JsonResponse
    {
        Gate::authorize('view', $reminder);

        return response()->json([
            ...ReminderResource::listItem($reminder, $this->today()),
            'body_html' => $reminder->body_html,
            'body_text' => $reminder->body_text,
        ]);
    }

    public function sendNow(Request $request, Reminder $reminder, ManualReminders $reminders): RedirectResponse
    {
        Gate::authorize('send', $reminder);
        $result = $reminders->sendNow($reminder, $this->user($request));

        return $this->back($result['status'] === 'queued' ? 'reminder_queued' : 'reminder_skipped');
    }

    public function cancel(Request $request, Reminder $reminder, ManualReminders $reminders): RedirectResponse
    {
        Gate::authorize('send', $reminder);
        $reminders->cancel($reminder, $this->user($request));

        return $this->back('reminder_canceled');
    }

    public function retry(Request $request, Reminder $reminder, ManualReminders $reminders): RedirectResponse
    {
        Gate::authorize('send', $reminder);
        $reminders->retry($reminder, $this->user($request));

        return $this->back('reminder_retried');
    }
}
