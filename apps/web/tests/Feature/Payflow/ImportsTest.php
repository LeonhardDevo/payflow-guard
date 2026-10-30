<?php

declare(strict_types=1);

use App\Domain\Collections\Models\Reminder;
use App\Domain\Imports\Models\Import;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Enums\WorkspaceRole;
use App\Models\OutboxMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Storage::fake('local');
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$this->user, $this->workspace] = $this->signInMember(WorkspaceRole::Finance);
    $this->workspace->forceFill(['timezone' => 'Europe/Riga'])->save();
    Payflow::pro($this->workspace);
});

function quickbooksCsv(): string
{
    return implode("\n", [
        'Date,Num,Customer,Email,Due date,Amount,Open balance,Memo/Description',
        '09/01/2026,QB-1001,Acme Corp,ap@acme.example,09/15/2026,"1,200.00",0.00,Website hosting',
        '09/10/2026,QB-1002,Acme Corp,ap@acme.example,10/10/2026,"2,400.00","1,000.00",Design sprint',
        '08/20/2026,QB-1003,Globex,billing@globex.example,08/23/2026,"5,000.00","5,000.00",Retainer',
        '10/15/2026,QB-1004,Initech,ap@initech.example,11/15/2026,750.00,750.00,Consulting',
        '10/18/2026,QB-1005,Initech,ap@initech.example,11/18/2026,300.00,300.00,Support',
        '10/19/2026,QB-1001,Acme Corp,ap@acme.example,11/19/2026,99.00,99.00,Duplicate',
    ])."\n";
}

function processImport(object $test, Import $import): array
{
    $offset = 0;

    do {
        $result = $test->internal('POST', '/internal/v1/payflow/imports/'.$import->id.'/process-batch', ['offset' => $offset, 'limit' => 2])->assertOk()->json();
        $offset = $result['next_offset'];
    } while (! $result['done']);

    return $result;
}

it('imports a QuickBooks file, pauses everything for review and activates with the first-contact cap', function (): void {
    $this->post(route('imports.store'), [
        'file' => UploadedFile::fake()->createWithContent('quickbooks.csv', quickbooksCsv()),
        'kind' => 'invoices',
        'preset' => 'quickbooks',
    ])->assertRedirect();

    $import = Import::query()->sole();
    expect($import->status->value)->toBe('mapping')
        ->and($import->mapping['number'])->toBe('Num')
        ->and($import->options['date_format'])->toBe('m/d/Y');

    $this->get(route('imports.show', $import))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('imports/Show')
        ->where('import.suggested_mapping.client_name', 'Customer')
        ->has('import.sample_rows', 5)
        ->has('import.fields'));

    $this->post(route('imports.start', $import), [
        'mapping' => $import->mapping,
        'options' => ['default_currency' => 'USD', 'date_format' => 'm/d/Y', 'create_missing_clients' => true, 'mark_as_sent' => true],
    ])->assertRedirect();

    expect(OutboxMessage::query()->where('topic', 'payflow.import.requested')->exists())->toBeTrue();
    $result = processImport($this, $import->refresh());
    $import->refresh();

    expect($result['done'])->toBeTrue()
        ->and($import->status->value)->toBe('completed_with_errors')
        ->and($import->rows_imported)->toBe(5)
        ->and($import->rows_skipped)->toBe(1)
        ->and($import->errors[0]['code'])->toBe('duplicate_number')
        ->and(Client::query()->count())->toBe(3)
        ->and(Invoice::query()->where('number', 'QB-1001')->value('status')?->value)->toBe('paid')
        ->and(Invoice::query()->where('number', 'QB-1002')->value('status')?->value)->toBe('partially_paid')
        ->and(Invoice::query()->where('reminders_pause_source', 'import_review')->count())->toBe(5);

    $this->internal('POST', '/internal/v1/payflow/workspaces/'.$this->workspace->id.'/reminders/plan', [])->assertOk();
    expect(Reminder::query()->count())->toBe(0);

    $this->get(route('imports.activation', $import))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('imports/Activation')
        ->where('preview.invoices', 4)
        ->where('preview.overdue', 2)
        ->where('preview.beyond_escalation', 1)
        ->where('preview.tones_capped.firm', 0)
        ->where('preview.tones_capped.final', 0)
        ->has('preview.first_slots')
        ->has('preview.samples')
        ->has('invoices', 4));

    $this->post(route('imports.activate', $import), ['allow_engine_tone' => false, 'exclude_invoice_ids' => []])->assertRedirect(route('invoices.index'));
    expect($import->refresh()->activation_status->value)->toBe('activated')
        ->and(Invoice::query()->where('reminders_paused', true)->count())->toBe(1);

    $this->internal('POST', '/internal/v1/payflow/workspaces/'.$this->workspace->id.'/reminders/plan', [])->assertOk();
    $tones = Reminder::query()->where('status', 'scheduled')->pluck('tone')->map->value->unique()->all();

    expect(Reminder::query()->where('status', 'scheduled')->count())->toBeGreaterThan(0)
        ->and(array_diff($tones, ['friendly', 'neutral']))->toBe([]);
});

it('refuses imports without the entitlement and imports clients', function (): void {
    $this->workspace->subscription()->update(['plan' => 'free']);
    $this->workspace->unsetRelation('subscription');

    $this->post(route('imports.store'), ['file' => UploadedFile::fake()->createWithContent('c.csv', "name\nA\n"), 'kind' => 'clients', 'preset' => 'generic'])->assertSessionHas('error');

    Payflow::pro($this->workspace);
    $csv = "Name;Email;Country;Currency\nNordlicht Apps;rechnung@nordlicht.example;DE;EUR\nBad;not-an-email;DE;EUR\nNordlicht Apps;x@y.example;DE;EUR\n";
    $this->post(route('imports.store'), ['file' => UploadedFile::fake()->createWithContent('clients.csv', $csv), 'kind' => 'clients', 'preset' => 'generic'])->assertRedirect();
    $import = Import::query()->sole();

    $this->post(route('imports.start', $import), [
        'mapping' => ['name' => 'Name', 'email' => 'Email', 'country' => 'Country', 'currency' => 'Currency'],
        'options' => ['default_currency' => 'USD', 'date_format' => 'Y-m-d', 'create_missing_clients' => true, 'mark_as_sent' => true],
    ])->assertRedirect();
    processImport($this, $import->refresh());

    expect($import->refresh()->rows_imported)->toBe(1)
        ->and($import->rows_failed)->toBe(1)
        ->and($import->rows_skipped)->toBe(1)
        ->and(Client::query()->where('name', 'Nordlicht Apps')->value('currency'))->toBe('EUR');

    $this->get(route('imports.template', 'invoices'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
});
