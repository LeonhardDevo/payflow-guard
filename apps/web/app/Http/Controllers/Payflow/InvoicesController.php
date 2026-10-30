<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Analytics\Enums\AgingBucket;
use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Payments\Links\PaymentAccountState;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Clients\ClientTerms;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Invoices\InvoiceDisplayStatusResolver;
use App\Domain\Receivables\Invoices\InvoiceDrafts;
use App\Domain\Receivables\Invoices\InvoiceIssuer;
use App\Domain\Receivables\Invoices\InvoiceNumbering;
use App\Domain\Receivables\Invoices\InvoiceQuery;
use App\Domain\Receivables\LateFees\LateFees;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Models\InvoiceEvent;
use App\Domain\Receivables\Pdf\InvoicePdf;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Http\Requests\Payflow\InvoiceRequest;
use App\Http\Resources\Payflow\ClientResource;
use App\Http\Resources\Payflow\InvoiceResource;
use App\Http\Resources\Payflow\Paginated;
use App\Http\Resources\Payflow\PaymentResource;
use App\Http\Resources\Payflow\ReminderResource;
use App\Http\Resources\Payflow\TimelineResource;
use App\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoicesController extends PayflowController
{
    /** @return array{status: string, client_id: int|null, q: string|null, currency: string|null, risk: string|null, aging: string|null, due_from: string|null, due_to: string|null, sort: string, per_page: int} */
    private function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'due_date');
        $perPage = (int) $request->query('per_page', 25);
        $date = fn (string $key): ?string => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1 ? (string) $request->query($key) : null;
        $q = trim((string) $request->query('q', ''));
        $currency = strtoupper(trim((string) $request->query('currency', '')));
        $risk = (string) $request->query('risk', '');
        $aging = (string) $request->query('aging', '');

        return [
            'status' => in_array($status, InvoiceQuery::STATUS_FILTERS, true) ? $status : 'all',
            'client_id' => ctype_digit((string) $request->query('client_id', '')) ? (int) $request->query('client_id') : null,
            'q' => $q !== '' ? mb_substr($q, 0, 100) : null,
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
            'risk' => RiskBand::tryFrom($risk)?->value,
            'aging' => AgingBucket::tryFrom($aging)?->value,
            'due_from' => $date('due_from'),
            'due_to' => $date('due_to'),
            'sort' => in_array($sort, InvoiceQuery::SORTS, true) ? $sort : 'due_date',
            'per_page' => in_array($perPage, [25, 50, 100], true) ? $perPage : 25,
        ];
    }

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $today = $this->today();
        $filters = $this->filters($request);
        $query = InvoiceQuery::apply(Invoice::query()->with('client'), $filters, $today);
        $paginator = $query->paginate($filters['per_page'])->withQueryString();
        $risk = $this->riskVisible();
        $items = InvoiceResource::listItems(collect($paginator->items()), $today, $risk);

        $counts = [];

        foreach (InvoiceQuery::STATUS_FILTERS as $status) {
            $counts[$status] = InvoiceQuery::status(Invoice::query(), $status, $today)->count();
        }

        $totals = Invoice::query()
            ->whereIn('status', Invoice::OUTSTANDING_STATUSES)
            ->get(['currency', 'status', 'due_date', 'total_minor', 'amount_paid_minor'])
            ->groupBy('currency')
            ->map(fn ($group, $currency): array => [
                'currency' => (string) $currency,
                'outstanding' => MoneyData::of((int) $group->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue())), (string) $currency),
                'overdue' => MoneyData::of((int) $group->filter(fn (Invoice $invoice): bool => $invoice->isOpen() && $invoice->dueDate() < $today)
                    ->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue())), (string) $currency),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return Inertia::render('invoices/Index', [
            'invoices' => Paginated::of($paginator, $items),
            'filters' => [...$filters, 'per_page' => $filters['per_page']],
            'counts' => $counts,
            'totals' => $totals,
            'clients' => Client::query()->orderBy('name')->limit(500)->get(['id', 'name'])
                ->map(fn (Client $client): array => ['value' => $client->id, 'label' => $client->name])->values()->all(),
            'currencies' => Invoice::query()->distinct()->orderBy('currency')->pluck('currency')->map(fn (mixed $c): string => (string) $c)->values()->all() ?: [$workspace->currency],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $today = $this->today();
        $filters = $this->filters($request);
        $query = InvoiceQuery::apply(Invoice::query()->with('client'), $filters, $today);

        return response()->streamDownload(function () use ($query, $today): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['number', 'client', 'status', 'display_status', 'issue_date', 'due_date', 'currency', 'total', 'amount_paid', 'balance_due', 'days_overdue'], ',', '"', '');

            $query->chunk(500, function ($invoices) use ($handle, $today): void {
                foreach ($invoices as $invoice) {
                    fputcsv($handle, [
                        $invoice->number ?? '',
                        $invoice->client->name,
                        $invoice->status->value,
                        InvoiceDisplayStatusResolver::for($invoice, $today)->value,
                        $invoice->issueDate(),
                        $invoice->dueDate(),
                        $invoice->currency,
                        MoneyFormatter::decimal($invoice->total_minor),
                        MoneyFormatter::decimal($invoice->amount_paid_minor),
                        MoneyFormatter::decimal($invoice->balanceDue()),
                        $invoice->isOpen() ? InvoiceDisplayStatusResolver::daysOverdue($invoice->dueDate(), $today) : 0,
                    ], ',', '"', '');
                }
            });

            fclose($handle);
        }, 'invoices-'.$today.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(Request $request, PlanLimits $limits, PaymentAccountState $accounts): Response
    {
        return Inertia::render('invoices/Create', $this->formProps(null, $request, $limits, $accounts));
    }

    public function edit(Invoice $invoice, Request $request, PlanLimits $limits, PaymentAccountState $accounts): Response
    {
        Gate::authorize('update', $invoice);

        return Inertia::render('invoices/Edit', $this->formProps($invoice, $request, $limits, $accounts));
    }

    /** @return array<string, mixed> */
    private function formProps(?Invoice $invoice, Request $request, PlanLimits $limits, PaymentAccountState $accounts): array
    {
        $workspace = $this->workspace();
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $today = $this->today();
        $clientId = ctype_digit((string) $request->query('client_id', '')) ? (int) $request->query('client_id') : null;
        $client = $clientId !== null ? Client::query()->find($clientId) : null;
        $terms = $client !== null ? ClientTerms::paymentTermsDays($client) : $settings->default_payment_terms_days;

        return [
            'invoice' => $invoice !== null ? InvoiceResource::detail($invoice, $today, $this->riskVisible(), app(PaymentLinkService::class)) : null,
            'clients' => Client::query()->orderBy('name')->limit(200)->get()->map(fn (Client $item): array => ClientResource::option($item))->values()->all(),
            'defaults' => [
                'client_id' => $client?->id,
                'currency' => $client->currency ?? $workspace->currency,
                'locale' => $client->locale ?? (in_array($workspace->locale, ['en', 'ru'], true) ? $workspace->locale : 'en'),
                'issue_date' => $today,
                'due_date' => BusinessDate::addDays($today, $terms),
                'payment_terms_days' => $terms,
                'tax_rate_bp' => 0,
                'notes' => null,
                'terms' => null,
            ],
            'next_number_preview' => InvoiceNumbering::preview($workspace->id, $today),
            'currencies' => self::currencies(),
            'provider_connected' => $accounts->live($workspace) !== null,
            'limits' => ['invoices_per_month' => ['used' => $limits->used($workspace, 'invoices_per_month'), 'limit' => $limits->limit($workspace, 'invoices_per_month')]],
        ];
    }

    public function store(InvoiceRequest $request, InvoiceDrafts $drafts, InvoiceIssuer $issuer): RedirectResponse
    {
        Gate::authorize('create', Invoice::class);
        $user = $this->user($request);
        $invoice = $drafts->create($this->workspace(), $user, $request->draftData());

        return $this->afterSave($request, $invoice, $issuer, 'invoice_created');
    }

    public function update(InvoiceRequest $request, Invoice $invoice, InvoiceDrafts $drafts, InvoiceIssuer $issuer): RedirectResponse
    {
        Gate::authorize('update', $invoice);
        $user = $this->user($request);

        if ($request->isOpenEdit()) {
            $drafts->updateOpen($invoice, $user, $request->openData());

            return $this->redirectToShow($invoice, 'invoice_updated');
        }

        $invoice = $drafts->updateDraft($invoice, $user, $request->draftData());

        return $this->afterSave($request, $invoice, $issuer, 'invoice_updated');
    }

    private function afterSave(InvoiceRequest $request, Invoice $invoice, InvoiceIssuer $issuer, string $flash): RedirectResponse
    {
        $user = $this->user($request);

        match ((string) $request->input('action')) {
            'save_and_send' => $issuer->send($invoice, $user),
            'save_and_mark_sent' => $issuer->markSent($invoice, $user),
            default => null,
        };

        return $this->redirectToShow($invoice, match ((string) $request->input('action')) {
            'save_and_send' => 'invoice_sent',
            'save_and_mark_sent' => 'invoice_marked_sent',
            default => $flash,
        });
    }

    private function redirectToShow(Invoice $invoice, string $flash): RedirectResponse
    {
        Flash::success('payflow.flash.'.$flash);

        return redirect()->route('invoices.show', $invoice->id, 303);
    }

    public function show(Invoice $invoice, Request $request, PaymentLinkService $links): Response
    {
        Gate::authorize('view', $invoice);
        $user = $this->user($request);
        $today = $this->today();
        $risk = $this->riskVisible();
        $client = $invoice->client;

        $reminders = Reminder::query()->with(['creator', 'step.policy'])->where('invoice_id', $invoice->id)->orderByDesc('scheduled_for')->orderByDesc('id')->get();
        $next = $reminders->filter(fn (Reminder $reminder): bool => $reminder->status === ReminderStatus::Scheduled)->sortBy('scheduled_for')->first();
        $canRecord = $user->can('recordPayment', $invoice);

        return Inertia::render('invoices/Show', [
            'invoice' => InvoiceResource::detail($invoice, $today, $risk, $links),
            'payments' => Payment::query()->with('recorder')->where('invoice_id', $invoice->id)->orderByDesc('paid_at')->get()
                ->map(fn (Payment $payment): array => PaymentResource::row($payment, $canRecord))->values()->all(),
            'reminders' => $reminders->map(fn (Reminder $reminder): array => ReminderResource::row($reminder))->values()->all(),
            'next_reminder' => $next !== null ? [...ReminderResource::row($next), 'policy_name' => $next->step !== null ? $next->step->policy->name : (ClientTerms::policy($client)->name ?? '')] : null,
            'tone' => $invoice->isOpen() ? ToneContext::suggest($invoice, $today)->toContract() : null,
            'late_fee' => LateFees::suggest($invoice, $today)?->toContract(),
            'timeline' => InvoiceEvent::query()->with('actor')->where('invoice_id', $invoice->id)->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()
                ->map(fn (InvoiceEvent $event): array => TimelineResource::event($event))->values()->all(),
            'client_stats' => [
                'avg_days_late' => $client->avgDaysLate(),
                'on_time_rate' => $client->onTimeRate(),
                'paid_invoices_count' => $client->paid_invoices_count,
                'open_balance' => MoneyData::of($client->open_balance_minor, $client->currency),
                'overdue_balance' => MoneyData::of($client->overdue_balance_minor, $client->currency),
            ],
            'can' => $this->pageCan([
                'edit' => $user->can('update', $invoice),
                'send' => $user->can('manage', $invoice) && in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Open, InvoiceStatus::PartiallyPaid], true),
                'record_payment' => $canRecord && in_array($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid, InvoiceStatus::Disputed, InvoiceStatus::Uncollectible], true),
                'remind' => $user->can('remind', $invoice) && $invoice->isOpen(),
                'void' => $user->can('writeOff', $invoice) && in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Open], true) && $invoice->amount_paid_minor === 0,
                'write_off' => $user->can('writeOff', $invoice) && $invoice->isOutstanding(),
                'dispute' => $user->can('manage', $invoice) && $invoice->isOpen(),
                'apply_late_fee' => $user->can('applyLateFee', $invoice) && $invoice->isOpen(),
                'delete' => $user->can('delete', $invoice),
            ]),
        ]);
    }

    public function destroy(Invoice $invoice, Request $request, InvoiceDrafts $drafts): RedirectResponse
    {
        Gate::authorize('delete', $invoice);
        $drafts->delete($invoice, $this->user($request));
        Flash::success('payflow.flash.invoice_deleted');

        return redirect()->route('invoices.index', [], 303);
    }

    public function pdf(Invoice $invoice): HttpResponse
    {
        Gate::authorize('view', $invoice);

        return response(InvoicePdf::render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.ClientEmailComposer::fileName($invoice).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
