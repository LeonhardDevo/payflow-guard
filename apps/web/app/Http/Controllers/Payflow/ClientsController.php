<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Analytics\Risk\RiskRecomputer;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Receivables\Clients\ClientService;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Enums\LatePayerClass;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Models\InvoiceEvent;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\UserRef;
use App\Http\Requests\Payflow\ClientRequest;
use App\Http\Requests\Payflow\PauseRequest;
use App\Http\Resources\Payflow\ClientResource;
use App\Http\Resources\Payflow\InvoiceResource;
use App\Http\Resources\Payflow\Paginated;
use App\Http\Resources\Payflow\ReminderResource;
use App\Http\Resources\Payflow\TimelineResource;
use App\Models\Document;
use App\Models\User;
use App\Support\Flash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ClientsController extends PayflowController
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $q = trim((string) $request->query('q', ''));
        $class = LatePayerClass::tryFrom((string) $request->query('class', ''));
        $ownerParam = (string) $request->query('owner_id', '');
        $owner = $ownerParam === 'me' ? 'me' : (ctype_digit($ownerParam) ? (int) $ownerParam : null);
        $pausedParam = $request->query('paused');
        $paused = $pausedParam === null || $pausedParam === '' ? null : filter_var($pausedParam, FILTER_VALIDATE_BOOLEAN);
        $sort = in_array($request->query('sort'), ['name', '-open_balance', '-overdue_balance', '-avg_days_late'], true) ? (string) $request->query('sort') : 'name';

        $query = Client::query()->with('owner')
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%'])
                ->orWhereRaw('lower(coalesce(legal_name, \'\')) like ?', ['%'.mb_strtolower($q).'%'])
                ->orWhereRaw('lower(coalesce(email, \'\')) like ?', ['%'.mb_strtolower($q).'%'])))
            ->when($class !== null, fn ($query) => $query->where('late_payer_class', $class?->value))
            ->when($owner !== null, fn ($query) => $query->where('owner_user_id', $owner === 'me' ? $user->id : $owner))
            ->when($paused !== null, fn ($query) => $query->where('reminders_paused', $paused));

        match ($sort) {
            '-open_balance' => $query->orderByDesc('open_balance_minor'),
            '-overdue_balance' => $query->orderByDesc('overdue_balance_minor'),
            '-avg_days_late' => $query->orderByDesc('avg_days_late'),
            default => $query->orderBy('name'),
        };

        $paginator = $query->orderBy('id')->paginate(25)->withQueryString();
        $clients = collect($paginator->items());
        $openCounts = ClientResource::openCounts($clients);

        $classCounts = [];

        foreach (LatePayerClass::cases() as $case) {
            $classCounts[$case->value] = Client::query()->where('late_payer_class', $case->value)->count();
        }

        return Inertia::render('clients/Index', [
            'clients' => Paginated::of($paginator, $clients->map(fn (Client $client): array => ClientResource::listItem($client, $openCounts[$client->id] ?? 0))->values()->all()),
            'filters' => ['q' => $q !== '' ? $q : null, 'class' => $class?->value, 'owner_id' => $owner, 'paused' => $paused, 'sort' => $sort],
            'class_counts' => $classCounts,
            'owners' => $this->members(),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        return response()->json(Client::query()
            ->when($q !== '', fn ($query) => $query->whereRaw('lower(name) like ?', ['%'.$q.'%']))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Client $client): array => ClientResource::option($client))
            ->values()
            ->all());
    }

    public function create(PlanLimits $limits): Response
    {
        Gate::authorize('create', Client::class);

        return Inertia::render('clients/Create', $this->formProps(null, $limits));
    }

    public function edit(Client $client, PlanLimits $limits): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('clients/Edit', $this->formProps($client, $limits));
    }

    /** @return array<string, mixed> */
    private function formProps(?Client $client, PlanLimits $limits): array
    {
        $workspace = $this->workspace();
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $default = $settings->default_reminder_policy_id !== null ? ReminderPolicy::query()->find($settings->default_reminder_policy_id) : null;

        return [
            'client' => $client !== null ? ClientResource::detail($client, $workspace) : null,
            'policies' => ReminderPolicy::query()->orderBy('name')->get()->map(fn (ReminderPolicy $policy): array => ['value' => $policy->id, 'label' => $policy->name])->values()->all(),
            'members' => $this->members(),
            'documents' => Document::query()->orderBy('title')->limit(200)->get(['id', 'title'])->map(fn (Document $document): array => ['id' => $document->id, 'title' => $document->title])->values()->all(),
            'currencies' => self::currencies(),
            'countries' => self::countries(),
            'workspace_defaults' => [
                'payment_terms_days' => $settings->default_payment_terms_days,
                'late_fee' => $settings->lateFeeTerms()->toContract($workspace->currency),
                'policy' => $default !== null ? ['value' => $default->id, 'label' => $default->name] : null,
            ],
            'limits' => ['clients' => ['used' => $limits->used($workspace, 'clients'), 'limit' => $limits->limit($workspace, 'clients')]],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function countries(): array
    {
        $names = trans('payflow.countries');
        $result = [];

        if (is_array($names)) {
            foreach ($names as $code => $name) {
                $result[] = ['value' => (string) $code, 'label' => (string) $name];
            }
        }

        usort($result, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $result;
    }

    /** @return list<array{value: int, label: string}> */
    private function members(): array
    {
        return array_values($this->workspace()->members()->orderBy('users.name')->get()
            ->map(fn (User $user): array => ['value' => $user->id, 'label' => UserRef::name($user)])
            ->all());
    }

    public function store(ClientRequest $request, ClientService $clients): RedirectResponse
    {
        Gate::authorize('create', Client::class);
        $client = $clients->create($this->workspace(), $this->user($request), $request->clientData());
        Flash::success('payflow.flash.client_created');

        return redirect()->route('clients.show', $client->id, 303);
    }

    public function show(Client $client, Request $request): Response
    {
        Gate::authorize('view', $client);
        $user = $this->user($request);
        $workspace = $this->workspace();
        $today = $this->today();
        $timezone = $workspace->timezone;

        $invoices = Invoice::query()->with('client')
            ->where('client_id', $client->id)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $behaviour = Invoice::query()->where('client_id', $client->id)
            ->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Uncollectible->value])
            ->orderByDesc('due_date')
            ->limit(30)
            ->get()
            ->filter(fn (Invoice $invoice): bool => RiskRecomputer::isClosed($invoice))
            ->take(12)
            ->reverse()
            ->map(fn (Invoice $invoice): array => [
                'invoice_id' => $invoice->id,
                'number' => $invoice->label(),
                'due_date' => $invoice->dueDate(),
                'paid_on' => ($invoice->paid_at ?? $invoice->written_off_at)?->setTimezone($timezone)->format('Y-m-d') ?? $invoice->dueDate(),
                'days_late' => RiskRecomputer::daysLate($invoice, $timezone),
                'amount' => MoneyData::of($invoice->total_minor, $invoice->currency),
            ])
            ->values()
            ->all();

        $reminders = Reminder::query()->with(['invoice', 'client', 'creator'])->where('client_id', $client->id)->orderByDesc('scheduled_for')->limit(20)->get();
        $next = Reminder::query()->with(['invoice', 'client'])->where('client_id', $client->id)->where('status', ReminderStatus::Scheduled->value)->orderBy('scheduled_for')->first();

        return Inertia::render('clients/Show', [
            'client' => ClientResource::detail($client, $workspace),
            'invoices' => InvoiceResource::listItems($invoices, $today, $this->riskVisible()),
            'behaviour' => $behaviour,
            'reminders' => $reminders->map(fn (Reminder $reminder): array => ReminderResource::listItem($reminder, $today))->values()->all(),
            'timeline' => InvoiceEvent::query()->with('actor')->where('client_id', $client->id)->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get()
                ->map(fn (InvoiceEvent $event): array => TimelineResource::event($event))->values()->all(),
            'next_reminder' => $next !== null ? ReminderResource::upcoming($next) : null,
            'can' => $this->pageCan([
                'edit' => $user->can('update', $client),
                'delete' => $user->can('delete', $client),
                'pause' => $user->can('pause', $client),
                'create_invoice' => $user->can('create', Invoice::class),
            ]),
        ]);
    }

    public function update(ClientRequest $request, Client $client, ClientService $clients): RedirectResponse
    {
        Gate::authorize('update', $client);
        $clients->update($client, $this->user($request), $request->clientData());
        Flash::success('payflow.flash.client_updated');

        return redirect()->route('clients.show', $client->id, 303);
    }

    public function destroy(Request $request, Client $client, ClientService $clients): RedirectResponse
    {
        Gate::authorize('delete', $client);
        $clients->delete($client, $this->user($request));
        Flash::success('payflow.flash.client_deleted');

        return redirect()->route('clients.index', [], 303);
    }

    public function pause(PauseRequest $request, Client $client, ClientService $clients): RedirectResponse
    {
        Gate::authorize('pause', $client);
        $paused = $request->boolean('paused');
        $clients->pause($client, $this->user($request), $paused, $request->input('until'), $request->input('reason'), $request->boolean('acknowledge_opt_out'));

        return $this->back($paused ? 'client_paused' : 'client_resumed');
    }

    public function anonymise(Request $request, Client $client, ClientService $clients): RedirectResponse
    {
        Gate::authorize('anonymise', $client);
        $data = $request->validate(['confirm_name' => ['required', 'string', Rule::in([$client->name])], 'natural_person' => ['sometimes', 'boolean']]);
        $clients->anonymise($client, $this->user($request), (bool) ($data['natural_person'] ?? false));
        Flash::success('payflow.flash.client_anonymised');

        return redirect()->route('clients.show', $client->id, 303);
    }
}
