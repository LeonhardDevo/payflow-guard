<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Imports\Enums\ImportKind;
use App\Domain\Imports\Enums\ImportPreset;
use App\Domain\Imports\ImportService;
use App\Domain\Imports\Mapping\DateFormats;
use App\Domain\Imports\Mapping\ImportFields;
use App\Domain\Imports\Models\Import;
use App\Http\Resources\Payflow\ImportResource;
use App\Http\Resources\Payflow\InvoiceResource;
use App\Http\Resources\Payflow\Paginated;
use App\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ImportsController extends PayflowController
{
    public function index(PlanLimits $limits): Response
    {
        $workspace = $this->workspace();
        $paginator = Import::query()->with('creator')->orderByDesc('id')->paginate(25);

        return Inertia::render('imports/Index', [
            'imports' => Paginated::of($paginator, collect($paginator->items())->map(fn (Import $import): array => ImportResource::listItem($import))->values()->all()),
            'presets' => array_map(fn (ImportPreset $preset): array => ['value' => $preset->value, 'label' => (string) __('payflow.imports.presets.'.$preset->value)], ImportPreset::cases()),
            'kinds' => array_map(fn (ImportKind $kind): array => ['value' => $kind->value, 'label' => (string) __('payflow.imports.kinds.'.$kind->value)], ImportKind::cases()),
            'limits' => ['imports_per_month' => ['used' => $limits->used($workspace, 'imports_per_month'), 'limit' => $limits->limit($workspace, 'imports_per_month')]],
            'entitled' => $limits->hasFeature($workspace, 'csv_import'),
        ]);
    }

    public function store(Request $request, ImportService $imports): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,text/x-csv'],
            'kind' => ['required', Rule::enum(ImportKind::class)],
            'preset' => ['required', Rule::enum(ImportPreset::class)],
        ]);

        $import = $imports->upload($this->workspace(), $this->user($request), $request->file('file'), ImportKind::from((string) $data['kind']), ImportPreset::from((string) $data['preset']));
        Flash::success('payflow.flash.import_uploaded');

        return redirect()->route('imports.show', $import->id, 303);
    }

    public function show(Import $import): Response
    {
        Gate::authorize('view', $import);

        return Inertia::render('imports/Show', [
            'import' => ImportResource::detail($import),
            'currencies' => self::currencies(),
        ]);
    }

    public function start(Request $request, Import $import, ImportService $imports): RedirectResponse
    {
        Gate::authorize('view', $import);
        $fields = array_keys(ImportFields::fields($import->kind));
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', Rule::in($import->headers)],
            'options.default_currency' => ['required', Rule::in(self::currencies())],
            'options.date_format' => ['required', Rule::in(DateFormats::SUPPORTED)],
            'options.create_missing_clients' => ['required', 'boolean'],
            'options.mark_as_sent' => ['required', 'boolean'],
        ]);

        $mapping = [];

        foreach ([...$fields, 'quantity', 'unit_amount'] as $field) {
            $value = $data['mapping'][$field] ?? null;
            $mapping[$field] = is_string($value) && $value !== '' ? $value : null;
        }

        $imports->start($import, $this->user($request), $mapping, [
            'default_currency' => (string) $data['options']['default_currency'],
            'date_format' => (string) $data['options']['date_format'],
            'create_missing_clients' => (bool) $data['options']['create_missing_clients'],
            'mark_as_sent' => (bool) $data['options']['mark_as_sent'],
        ]);
        Flash::success('payflow.flash.import_started');

        return redirect()->route('imports.show', $import->id, 303);
    }

    public function activation(Import $import, ImportService $imports): Response
    {
        Gate::authorize('activate', $import);

        return Inertia::render('imports/Activation', [
            'import' => ImportResource::listItem($import),
            'preview' => $imports->activationPreview($import),
            'invoices' => InvoiceResource::listItems(ImportService::pendingInvoices($import)->take(200)->toBase(), $this->today(), $this->riskVisible()),
        ]);
    }

    public function activate(Request $request, Import $import, ImportService $imports): RedirectResponse
    {
        Gate::authorize('activate', $import);
        $data = $request->validate([
            'allow_engine_tone' => ['required', 'boolean'],
            'exclude_invoice_ids' => ['present', 'array', 'max:5000'],
            'exclude_invoice_ids.*' => ['integer'],
        ]);
        $imports->activate($import, $this->user($request), (bool) $data['allow_engine_tone'], array_values(array_map('intval', (array) $data['exclude_invoice_ids'])));
        Flash::success('payflow.flash.import_activated');

        return redirect()->route('invoices.index', [], 303);
    }

    public function dismiss(Request $request, Import $import, ImportService $imports): RedirectResponse
    {
        Gate::authorize('activate', $import);
        $imports->dismiss($import, $this->user($request));
        Flash::success('payflow.flash.import_dismissed');

        return redirect()->route('imports.show', $import->id, 303);
    }

    public function template(string $kind): HttpResponse
    {
        $importKind = ImportKind::from($kind);
        $fields = array_keys(ImportFields::fields($importKind));
        $example = $importKind === ImportKind::Invoices
            ? ['INV-1001', 'Acme Corp', 'ap@acme.example', '2026-09-01', '2026-09-15', 'USD', '1200.00', '1200.00', '0.00', '', 'PO-1', 'Consulting']
            : ['Acme Corp', 'ap@acme.example', 'Jane Doe', '+1 555 0100', 'US', 'USD', '30', '', '', 'ACME-1'];

        return response(implode(',', $fields)."\n".implode(',', $example)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="payflow-'.$kind.'-template.csv"',
        ]);
    }
}
