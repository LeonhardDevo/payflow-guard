<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Analytics\ReceivablesAnalytics;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Guard\Text\NameNormalizer;
use App\Domain\Guard\Text\TrigramSimilarity;
use App\Domain\Payments\Models\Payment;
use App\Domain\Privacy\PiiRedactor;
use App\Domain\Receivables\Clients\ClientTerms;
use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Receivables\Invoices\InvoiceDisplayStatusResolver;
use App\Domain\Receivables\LateFees\LateFees;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Money\MoneyParser;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Locale\WithLocale;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiDataController extends Controller
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  Closure(Workspace, string, CarbonImmutable): (array{data: array<string, mixed>, summary: string, resolved?: array<string, mixed>}|JsonResponse)  $handler */
    protected function respond(Request $request, string $ability, Closure $handler): JsonResponse
    {
        $workspace = Workspace::query()->find((int) $request->query('workspace_id'));
        $user = User::query()->find((int) $request->query('user_id'));
        $locale = in_array($request->query('locale'), ['en', 'ru'], true) ? (string) $request->query('locale') : 'en';

        if ($workspace === null || $user === null) {
            return response()->json(['error' => 'not_found', 'summary' => (string) trans('payflow.ai.not_found', [], $locale)], 404);
        }

        $role = $user->roleIn($workspace);

        if (! $role instanceof WorkspaceRole) {
            return response()->json(['error' => 'not_a_member', 'summary' => (string) trans('payflow.ai.forbidden', [], $locale)], 403);
        }

        if (! $role->allows($ability)) {
            return response()->json(['error' => 'forbidden', 'summary' => (string) trans('payflow.ai.forbidden', [], $locale)], 403);
        }

        $now = $this->clock->now();
        $result = app(CurrentWorkspace::class)->runAs($workspace, fn (): mixed => WithLocale::run($locale, fn (): mixed => $handler($workspace, $locale, $now)), $role);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $payload = [...$result, 'as_of' => Iso::dateTime($now)];

        if ($workspace->ai_external_allowed) {
            $payload['data'] = PiiRedactor::value($payload['data']);
            $payload['summary'] = PiiRedactor::text($payload['summary']);
        }

        return response()->json($payload);
    }

    /** @return array{minor: int, currency: string, formatted: string} */
    protected static function money(int $minor, string $currency, string $locale): array
    {
        return MoneyData::formatted($minor, $currency, $locale);
    }

    /** @return array{iso: string, formatted: string}|null */
    protected static function date(?string $date, string $locale): ?array
    {
        if ($date === null) {
            return null;
        }

        $day = CarbonImmutable::parse($date)->locale($locale);

        return ['iso' => $date, 'formatted' => $day instanceof CarbonImmutable ? $day->isoFormat('LL') : $date];
    }

    /** @return array{iso: string, formatted: string}|null */
    protected static function instant(?CarbonInterface $instant, string $timezone, string $locale): ?array
    {
        if ($instant === null) {
            return null;
        }

        $local = CarbonImmutable::parse($instant)->setTimezone($timezone)->locale($locale);

        return ['iso' => (string) Iso::dateTime($instant), 'formatted' => $local instanceof CarbonImmutable ? $local->isoFormat('LLL') : (string) Iso::dateTime($instant)];
    }

    private function resolveClient(?string $query, string $locale): Client|JsonResponse
    {
        $query = trim((string) $query);

        if ($query === '') {
            return response()->json(['error' => 'not_found', 'summary' => (string) trans('payflow.ai.not_found', [], $locale)]);
        }

        $exact = Client::query()->where(fn ($inner) => $inner->whereRaw('lower(name) = ?', [mb_strtolower($query)])->orWhereRaw('lower(coalesce(legal_name, \'\')) = ?', [mb_strtolower($query)]))->first();

        if ($exact !== null) {
            return $exact;
        }

        $normalized = NameNormalizer::normalize($query);
        $scored = Client::query()->get()->map(function (Client $client) use ($normalized): array {
            $score = max(
                TrigramSimilarity::similarity($normalized, NameNormalizer::normalize($client->name)),
                $client->legal_name !== null ? TrigramSimilarity::similarity($normalized, NameNormalizer::normalize($client->legal_name)) : 0.0,
            );

            return ['client' => $client, 'score' => $score];
        })->filter(fn (array $row): bool => $row['score'] >= 0.3)->sortByDesc('score')->values();

        if ($scored->isEmpty()) {
            return response()->json(['error' => 'not_found', 'summary' => (string) trans('payflow.ai.not_found', [], $locale)]);
        }

        if ($scored->count() > 1 && $scored[0]['score'] - $scored[1]['score'] < 0.05) {
            $candidates = $scored->take(5)->map(fn (array $row): array => ['id' => $row['client']->id, 'name' => $row['client']->name])->values()->all();

            return response()->json([
                'error' => 'ambiguous',
                'summary' => (string) trans('payflow.ai.ambiguous', ['names' => implode(', ', array_column($candidates, 'name'))], $locale),
                'candidates' => $candidates,
            ]);
        }

        return $scored[0]['client'];
    }

    /** @return array{kind: string, id: int, name: string, score: float} */
    private static function resolved(Client $client, string $query): array
    {
        return [
            'kind' => 'client',
            'id' => $client->id,
            'name' => $client->name,
            'score' => round(TrigramSimilarity::similarity(NameNormalizer::normalize($query), NameNormalizer::normalize($client->name)), 2),
        ];
    }

    public function overdue(Request $request): JsonResponse
    {
        return $this->respond($request, 'invoices.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request): array|JsonResponse {
            $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
            $client = null;

            if ($request->filled('client_query')) {
                $client = $this->resolveClient((string) $request->query('client_query'), $locale);

                if ($client instanceof JsonResponse) {
                    return $client;
                }
            }

            $limit = max(1, min(10, (int) $request->query('limit', '5')));
            $currency = is_string($request->query('currency')) ? strtoupper((string) $request->query('currency')) : null;
            $query = Invoice::query()->with('client')
                ->whereIn('status', Invoice::OPEN_STATUSES)
                ->where('due_date', '<', $today)
                ->when($client !== null, fn ($inner) => $inner->where('client_id', $client?->id))
                ->when($currency !== null, fn ($inner) => $inner->where('currency', $currency));
            $all = (clone $query)->get();
            $sorted = $request->query('sort') === 'amount'
                ? $all->sortByDesc(fn (Invoice $invoice): int => $invoice->balanceDue())
                : $all->sortBy(fn (Invoice $invoice): string => $invoice->dueDate());
            $next = Reminder::query()->whereIn('invoice_id', $all->modelKeys())->where('status', ReminderStatus::Scheduled->value)
                ->get()->groupBy('invoice_id')->map(fn ($group) => $group->sortBy('scheduled_for')->first());

            $items = $sorted->take($limit)->map(fn (Invoice $invoice): array => [
                'number' => $invoice->label(),
                'client' => $invoice->client->name,
                'days_overdue' => InvoiceDisplayStatusResolver::daysOverdue($invoice->dueDate(), $today),
                'balance_due' => self::money($invoice->balanceDue(), $invoice->currency, $locale),
                'due_date' => self::date($invoice->dueDate(), $locale),
                'risk_band' => $invoice->risk_band?->value,
                'reminders_sent' => $invoice->reminders_sent_count,
                'next_reminder_at' => self::instant($next->get($invoice->id)?->scheduled_for, $workspace->timezone, $locale),
                'url' => route('invoices.show', $invoice->id),
            ])->values()->all();

            $totals = $all->groupBy('currency')->map(fn ($group, $cur): array => self::money((int) $group->sum(fn (Invoice $invoice): int => $invoice->balanceDue()), (string) $cur, $locale))->values()->all();
            $summary = $all->isEmpty()
                ? (string) __('payflow.ai.no_overdue')
                : implode("\n", [
                    (string) __('payflow.ai.overdue_summary', ['count' => $all->count(), 'total' => implode(' · ', array_column($totals, 'formatted'))]),
                    ...array_map(fn (array $item): string => (string) __('payflow.ai.overdue_item', [
                        'number' => $item['number'], 'client' => $item['client'], 'days' => $item['days_overdue'], 'amount' => $item['balance_due']['formatted'],
                    ]), $items),
                ]);

            return [
                'data' => ['items' => $items, 'totals' => $totals, 'count' => $all->count()],
                'summary' => $summary,
                ...($client !== null ? ['resolved' => self::resolved($client, (string) $request->query('client_query'))] : []),
            ];
        });
    }

    public function lookup(Request $request): JsonResponse
    {
        return $this->respond($request, 'invoices.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request): array|JsonResponse {
            $number = trim((string) $request->query('number', ''));
            $invoice = $number !== '' ? Invoice::query()->with('client')->whereRaw('lower(number) = ?', [mb_strtolower($number)])->first() : null;

            if ($invoice === null) {
                return response()->json(['error' => 'not_found', 'summary' => (string) __('payflow.ai.not_found')]);
            }

            $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
            $next = Reminder::query()->where('invoice_id', $invoice->id)->where('status', ReminderStatus::Scheduled->value)->orderBy('scheduled_for')->first();
            $fee = LateFees::suggest($invoice, $today);
            $tone = $invoice->isOpen() ? ToneContext::suggest($invoice, $today) : null;
            $status = InvoiceDisplayStatusResolver::for($invoice, $today)->value;

            return [
                'data' => [
                    'number' => $invoice->label(),
                    'client' => $invoice->client->name,
                    'status' => $invoice->status->value,
                    'display_status' => $status,
                    'total' => self::money($invoice->total_minor, $invoice->currency, $locale),
                    'balance_due' => self::money($invoice->balanceDue(), $invoice->currency, $locale),
                    'issue_date' => self::date($invoice->issueDate(), $locale),
                    'due_date' => self::date($invoice->dueDate(), $locale),
                    'days_overdue' => $invoice->isOpen() ? InvoiceDisplayStatusResolver::daysOverdue($invoice->dueDate(), $today) : 0,
                    'last_reminder_at' => self::instant($invoice->last_reminder_at, $workspace->timezone, $locale),
                    'next_reminder' => $next !== null ? ['at' => self::instant($next->scheduled_for, $workspace->timezone, $locale), 'tone' => $next->tone->value] : null,
                    'payments' => Payment::query()->where('invoice_id', $invoice->id)->whereIn('status', Payment::COUNTED_STATUSES)->orderBy('paid_at')->get()
                        ->map(fn (Payment $payment): array => [
                            'amount' => self::money($payment->amount_minor, $payment->currency, $locale),
                            'paid_at' => self::instant($payment->paid_at, $workspace->timezone, $locale),
                            'method' => $payment->method->value,
                        ])->values()->all(),
                    'late_fee_suggestion' => $fee !== null ? self::money($fee->amountMinor, $invoice->currency, $locale) : null,
                    'tone' => $tone !== null ? ['tone' => $tone->tone->value, 'reasons' => array_map(fn (array $reason): string => (string) __('payflow.reasons.'.$reason['code'], $reason['params']), $tone->reasons)] : null,
                    'url' => route('invoices.show', $invoice->id),
                ],
                'summary' => (string) __('payflow.ai.invoice_summary', [
                    'number' => $invoice->label(),
                    'client' => $invoice->client->name,
                    'status' => (string) __('payflow.statuses.'.$status),
                    'balance' => MoneyFormatter::format($invoice->balanceDue(), $invoice->currency, $locale),
                    'due_date' => (string) self::date($invoice->dueDate(), $locale)['formatted'],
                ]),
            ];
        });
    }

    public function clientProfile(Request $request): JsonResponse
    {
        return $this->respond($request, 'clients.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request): array|JsonResponse {
            $client = $this->resolveClient((string) $request->query('client_query'), $locale);

            if ($client instanceof JsonResponse) {
                return $client;
            }

            $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
            $open = Invoice::query()->where('client_id', $client->id)->whereIn('status', Invoice::OUTSTANDING_STATUSES)->orderBy('due_date')->limit(5)->get();
            $payments = Payment::query()->where('client_id', $client->id)->whereIn('status', Payment::COUNTED_STATUSES)->orderByDesc('paid_at')->limit(3)->get();
            $class = (string) __('payflow.classes.'.$client->late_payer_class->value);

            return [
                'data' => [
                    'client' => [
                        'name' => $client->name,
                        'class' => $client->late_payer_class->value,
                        'avg_days_late' => $client->avgDaysLate(),
                        'on_time_rate' => $client->onTimeRate(),
                        'paid_invoices_count' => $client->paid_invoices_count,
                        'open_balance' => self::money($client->open_balance_minor, $client->currency, $locale),
                        'overdue_balance' => self::money($client->overdue_balance_minor, $client->currency, $locale),
                        'payment_terms_days' => ClientTerms::paymentTermsDays($client),
                        'owner' => $client->owner !== null ? UserRef::name($client->owner) : null,
                        'tone_ceiling' => $client->tone_ceiling->value,
                        'reminders_paused' => $client->reminders_paused,
                        'opted_out' => $client->isOptedOut(),
                    ],
                    'open_invoices' => $open->map(fn (Invoice $invoice): array => [
                        'number' => $invoice->label(),
                        'balance_due' => self::money($invoice->balanceDue(), $invoice->currency, $locale),
                        'due_date' => self::date($invoice->dueDate(), $locale),
                        'days_overdue' => $invoice->isOpen() ? InvoiceDisplayStatusResolver::daysOverdue($invoice->dueDate(), $today) : 0,
                        'status' => $invoice->status->value,
                    ])->values()->all(),
                    'last_payments' => $payments->map(fn (Payment $payment): array => [
                        'amount' => self::money($payment->amount_minor, $payment->currency, $locale),
                        'paid_at' => self::instant($payment->paid_at, $workspace->timezone, $locale),
                    ])->values()->all(),
                ],
                'summary' => (string) __('payflow.ai.client_summary', [
                    'client' => $client->name,
                    'class' => $class,
                    'avg' => number_format($client->avgDaysLate(), 1),
                    'open' => MoneyFormatter::format($client->open_balance_minor, $client->currency, $locale),
                    'overdue' => MoneyFormatter::format($client->overdue_balance_minor, $client->currency, $locale),
                ]),
                'resolved' => self::resolved($client, (string) $request->query('client_query')),
            ];
        });
    }

    public function nextReminder(Request $request): JsonResponse
    {
        return $this->respond($request, 'invoices.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request): array|JsonResponse {
            $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
            $client = null;
            $query = Invoice::query()->with('client')->whereIn('status', Invoice::OPEN_STATUSES);

            if ($request->filled('invoice_number')) {
                $query->whereRaw('lower(number) = ?', [mb_strtolower(trim((string) $request->query('invoice_number')))]);
            } elseif ($request->filled('client_query')) {
                $client = $this->resolveClient((string) $request->query('client_query'), $locale);

                if ($client instanceof JsonResponse) {
                    return $client;
                }

                $query->where('client_id', $client->id);
            }

            $invoices = $query->orderBy('due_date')->limit(10)->get();
            $items = [];
            $summary = [];
            $recommendation = 'none';

            foreach ($invoices as $invoice) {
                $next = Reminder::query()->with('step.policy')->where('invoice_id', $invoice->id)->where('status', ReminderStatus::Scheduled->value)->orderBy('scheduled_for')->first();
                $policy = ClientTerms::policy($invoice->client);
                $paused = $invoice->reminders_paused || $invoice->client->reminders_paused;
                $days = InvoiceDisplayStatusResolver::daysOverdue($invoice->dueDate(), $today);
                $blocked = $paused ? 'paused' : ($policy !== null && $invoice->reminders_sent_count >= $policy->max_reminders ? 'limit_reached' : null);
                $tone = $next !== null ? $next->tone->value : ToneContext::suggest($invoice, $today)->tone->value;
                $reasons = $next !== null ? $next->tone_reasons : ToneContext::suggest($invoice, $today)->reasons;

                $items[] = [
                    'invoice_number' => $invoice->label(),
                    'client' => $invoice->client->name,
                    'next_reminder_at' => self::instant($next?->scheduled_for, $workspace->timezone, $locale),
                    'tone' => $tone,
                    'tone_reasons' => array_map(fn (array $reason): string => (string) __('payflow.reasons.'.$reason['code'], $reason['params']), $reasons),
                    'step_offset_days' => $next?->step_offset_days,
                    'policy_name' => $policy?->name,
                    'paused' => $paused,
                    'pause_reason' => $invoice->client->pause_reason,
                    'blocked_reason' => $blocked,
                ];

                $summary[] = $next !== null
                    ? (string) __('payflow.ai.next_reminder', ['number' => $invoice->label(), 'at' => (string) self::instant($next->scheduled_for, $workspace->timezone, $locale)['formatted'], 'tone' => (string) __('payflow.tones.'.$tone)])
                    : (string) __('payflow.ai.no_next_reminder', ['number' => $invoice->label()]);

                $candidate = match (true) {
                    $paused => 'paused',
                    $blocked === 'limit_reached' || ($policy !== null && $days >= $policy->escalate_after_days) => 'escalate',
                    $invoice->reminders_sent_count >= 3 && $days >= 14 => 'call',
                    $next === null && $days >= 1 => 'send_now',
                    $next !== null => 'wait',
                    default => 'none',
                };
                $priority = ['none' => 0, 'wait' => 1, 'paused' => 2, 'send_now' => 3, 'call' => 4, 'escalate' => 5];

                if ($priority[$candidate] > $priority[$recommendation]) {
                    $recommendation = $candidate;
                }
            }

            $text = (string) __('payflow.ai.recommendation.'.$recommendation);

            if ($invoices->isEmpty()) {
                return response()->json(['error' => 'not_found', 'summary' => (string) __('payflow.ai.not_found')]);
            }

            return [
                'data' => ['items' => $items, 'recommendation' => ['code' => $recommendation, 'text' => $text]],
                'summary' => implode("\n", [...$summary, $text]),
                ...($client instanceof Client ? ['resolved' => self::resolved($client, (string) $request->query('client_query'))] : []),
            ];
        });
    }

    public function clientTerms(Request $request): JsonResponse
    {
        return $this->respond($request, 'clients.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request): array|JsonResponse {
            $client = $this->resolveClient((string) $request->query('client_query'), $locale);

            if ($client instanceof JsonResponse) {
                return $client;
            }

            $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
            $terms = ClientTerms::lateFeeTerms($client);
            $document = $client->contract_document_id !== null ? Document::query()->find($client->contract_document_id) : null;
            $accrued = [];

            foreach (Invoice::query()->where('client_id', $client->id)->whereIn('status', Invoice::OPEN_STATUSES)->orderBy('due_date')->get() as $invoice) {
                $fee = LateFees::suggest($invoice, $today);

                if ($fee !== null) {
                    $accrued[] = ['invoice_number' => $invoice->label(), 'amount' => self::money($fee->amountMinor + $fee->alreadyAppliedMinor, $invoice->currency, $locale), 'chargeable_days' => $fee->chargeableDays];
                }
            }

            $fixed = $terms->fixedMinor !== null ? self::money($terms->fixedMinor, $client->currency, $locale) : null;
            $feeText = match ($terms->type) {
                LateFeeType::None => (string) __('payflow.ai.fee_none'),
                LateFeeType::Fixed => (string) __('payflow.ai.fee_fixed', ['amount' => $fixed['formatted'] ?? '', 'grace' => $terms->graceDays]),
                default => (string) __('payflow.ai.fee_'.$terms->type->value, ['rate' => MoneyParser::basisPointsToPercent($terms->rateBp) ?? '0', 'grace' => $terms->graceDays]),
            };

            return [
                'data' => [
                    'client' => $client->name,
                    'payment_terms_days' => ClientTerms::paymentTermsDays($client),
                    'late_fee' => [
                        'type' => $terms->type->value,
                        'rate_percent' => MoneyParser::basisPointsToPercent($terms->rateBp),
                        'fixed' => $fixed,
                        'grace_days' => $terms->graceDays,
                        'cap_percent' => MoneyParser::basisPointsToPercent($terms->capBp),
                        'source' => ClientTerms::lateFeeSource($client),
                    ],
                    'contract_document' => $document !== null ? ['id' => $document->id, 'title' => $document->title] : null,
                    'accrued_now' => $accrued,
                ],
                'summary' => (string) __('payflow.ai.terms_summary', ['client' => $client->name, 'days' => ClientTerms::paymentTermsDays($client), 'fee' => $feeText]),
                'resolved' => self::resolved($client, (string) $request->query('client_query')),
            ];
        });
    }

    public function arSummary(Request $request): JsonResponse
    {
        return $this->respond($request, 'analytics.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request): array {
            $analytics = new ReceivablesAnalytics($workspace, $now);
            $currency = $analytics->resolveCurrency(is_string($request->query('currency')) ? $request->query('currency') : null);
            $kpis = $analytics->kpis($currency, true);
            $format = fn (array $money): array => self::money((int) $money['minor'], (string) $money['currency'], $locale);

            return [
                'data' => [
                    'currency' => $currency,
                    'outstanding' => $format($kpis['outstanding']['amount']),
                    'overdue' => $format($kpis['overdue']['amount']),
                    'overdue_count' => $kpis['overdue']['count'],
                    'aging' => $analytics->aging($currency),
                    'dso' => $kpis['dso']['days'],
                    'collected_this_month' => $format($kpis['collected_this_month']['amount']),
                    'at_risk' => $kpis['at_risk'] !== null ? $format($kpis['at_risk']['amount']) : null,
                ],
                'summary' => (string) __('payflow.ai.ar_summary', [
                    'outstanding' => MoneyFormatter::formatArray($kpis['outstanding']['amount'], $locale),
                    'overdue' => MoneyFormatter::formatArray($kpis['overdue']['amount'], $locale),
                    'count' => $kpis['overdue']['count'],
                    'dso' => $kpis['dso']['days'] ?? '—',
                ]),
            ];
        });
    }

    public function latePayers(Request $request): JsonResponse
    {
        return $this->respond($request, 'clients.view', function (Workspace $workspace, string $locale, CarbonImmutable $now) use ($request): array {
            $limit = max(1, min(10, (int) $request->query('limit', '5')));
            $clients = Client::query()->whereIn('late_payer_class', ['chronic', 'occasional'])->orderByDesc('avg_days_late')->limit($limit)->get();
            $open = Invoice::query()->whereIn('client_id', $clients->modelKeys())->whereIn('status', Invoice::OUTSTANDING_STATUSES)
                ->selectRaw('client_id, count(*) as total')->groupBy('client_id')->pluck('total', 'client_id');

            $items = $clients->map(fn (Client $client): array => [
                'client' => $client->name,
                'class' => $client->late_payer_class->value,
                'avg_days_late' => $client->avgDaysLate(),
                'on_time_rate' => $client->onTimeRate(),
                'overdue_balance' => self::money($client->overdue_balance_minor, $client->currency, $locale),
                'open_invoices' => (int) ($open[$client->id] ?? 0),
            ])->values()->all();

            return [
                'data' => ['items' => $items],
                'summary' => $items === [] ? (string) __('payflow.ai.not_found') : implode("\n", array_map(fn (array $item): string => (string) __('payflow.ai.late_payers', [
                    'client' => $item['client'],
                    'class' => (string) __('payflow.classes.'.$item['class']),
                    'avg' => number_format((float) $item['avg_days_late'], 1),
                    'overdue' => $item['overdue_balance']['formatted'],
                ]), $items)),
            ];
        });
    }
}
