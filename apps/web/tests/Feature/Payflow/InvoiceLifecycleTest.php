<?php

declare(strict_types=1);

use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Models\InvoiceEvent;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Storage::fake('local');
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$this->user, $this->workspace] = $this->signInMember(WorkspaceRole::Finance);
    Payflow::pro($this->workspace);
    Payflow::fakeAccount($this->workspace);
    $this->client = Payflow::client($this->workspace, ['name' => 'QA Client', 'email' => 'qa-client@e2e.example']);
});

function invoicePayload(int $clientId, string $action = 'save_and_send'): array
{
    return [
        'client_id' => $clientId,
        'currency' => 'USD',
        'locale' => 'en',
        'issue_date' => '2026-10-22',
        'due_date' => '2026-11-05',
        'items' => [
            ['description' => 'Discovery workshop', 'quantity' => '1', 'unit_price' => '1200.00', 'tax_rate' => null],
            ['description' => 'Prototype', 'quantity' => '10', 'unit_price' => '150.00', 'tax_rate' => '20'],
        ],
        'action' => $action,
    ];
}

it('creates and sends an invoice with totals, number, PDF attachment, payment link and audit trail', function (): void {
    $this->post(route('invoices.store'), invoicePayload($this->client->id))->assertRedirect();

    $invoice = Invoice::query()->sole();
    $emails = Payflow::emails();

    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->number)->toBe('INV-2026-0001')
        ->and($invoice->subtotal_minor)->toBe(270000)
        ->and($invoice->tax_minor)->toBe(30000)
        ->and($invoice->total_minor)->toBe(300000)
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($invoice->risk_score)->not->toBeNull()
        ->and(InvoiceEvent::query()->where('invoice_id', $invoice->id)->pluck('type')->map->value->all())->toContain('created', 'issued', 'sent', 'link_created')
        ->and(InvoicePaymentLink::query()->where('invoice_id', $invoice->id)->where('active', true)->value('amount'))->toBe(300000)
        ->and(AuditLog::query()->whereIn('action', ['payflow.invoice.created', 'payflow.invoice.issued', 'payflow.invoice.sent'])->count())->toBe(3)
        ->and($emails)->toHaveCount(1)
        ->and(Payflow::emailPayloadErrors($emails[0]))->toBeNull()
        ->and($emails[0]['to'])->toBe('qa-client@e2e.example')
        ->and($emails[0]['reference'])->toEqual(['type' => 'invoice', 'id' => $invoice->id])
        ->and($emails[0]['attachments'][0]['internal_path'])->toBe('/internal/v1/payflow/invoices/'.$invoice->id.'/pdf?workspace_id='.$this->workspace->id)
        ->and($emails[0]['subject'])->toContain('INV-2026-0001')
        ->and($emails[0]['html'])->toContain('/i/'.$invoice->public_token)
        ->and($emails[0]['html'])->toContain('reminder-recipients')
        ->and($emails[0]['html'])->toContain('/i/'.$invoice->public_token.'/reminders')
        ->and($emails[0]['retry_profile'])->toBe('client_mail');
});

it('renders the invoice pages with the contract props', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-01', '2026-10-15');

    $this->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invoices/Show')
            ->has('invoice', fn (Assert $detail) => $detail
                ->where('id', $invoice->id)
                ->where('status', 'open')
                ->where('display_status', 'overdue')
                ->where('days_overdue', 7)
                ->where('days_until_due', null)
                ->where('total', ['minor' => 100000, 'currency' => 'USD'])
                ->where('balance_due', ['minor' => 100000, 'currency' => 'USD'])
                ->has('client', fn (Assert $client) => $client->where('id', $this->client->id)->has('name')->has('late_payer_class')->has('email')->has('locale')->has('tone_ceiling')->has('reminders_paused')->has('reminders_pause_source'))
                ->has('items', 1)
                ->has('risk')
                ->has('payment_link')
                ->where('payable_reason', null)
                ->etc())
            ->has('payments')
            ->has('reminders')
            ->has('next_reminder')
            ->where('tone.tone', 'friendly')
            ->has('late_fee')
            ->has('timeline')
            ->has('client_stats', 5)
            ->where('can.record_payment', true)
            ->where('can.void', true)
            ->where('can.delete', false));

    $this->get(route('invoices.index', ['status' => 'overdue']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invoices/Index')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.display_status', 'overdue')
            ->has('invoices.meta', 4)
            ->where('counts.overdue', 1)
            ->where('counts.all', 1)
            ->where('totals.0.overdue', ['minor' => 100000, 'currency' => 'USD'])
            ->has('filters')
            ->has('clients', 1));

    $this->get(route('invoices.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('invoices/Create')
        ->where('invoice', null)
        ->where('next_number_preview', 'INV-2026-0002')
        ->where('provider_connected', true)
        ->has('defaults')
        ->has('limits.invoices_per_month'));

    $this->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('records partial and full payments, reverses manual ones and keeps the link amount in sync', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-01', '2026-10-30');

    $this->post(route('invoices.payments.store', $invoice), ['amount' => '400.00', 'paid_on' => '2026-10-21', 'method' => 'bank_transfer', 'reference' => 'TRF-1', 'send_receipt' => true])
        ->assertRedirect();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoice->balanceDue())->toBe(60000)
        ->and(InvoicePaymentLink::query()->where('invoice_id', $invoice->id)->where('active', true)->value('amount'))->toBe(60000)
        ->and(collect(Payflow::emails())->pluck('reference.type'))->toContain('payment_receipt');

    $this->post(route('invoices.payments.store', $invoice), ['amount' => '700.00', 'paid_on' => '2026-10-22', 'method' => 'cash'])
        ->assertSessionHas('error');

    $payment = Payment::query()->sole();
    $this->post(route('payments.reverse', $payment), ['reason' => 'Bounced transfer'])->assertRedirect();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Open)->and($payment->refresh()->status->value)->toBe('reversed');

    $this->post(route('invoices.payments.store', $invoice), ['amount' => '1000.00', 'paid_on' => '2026-10-22', 'method' => 'card'])->assertRedirect();
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->paid_at)->not->toBeNull()
        ->and(InvoicePaymentLink::query()->where('invoice_id', $invoice->id)->where('active', true)->exists())->toBeFalse()
        ->and(InvoicePaymentLink::query()->where('invoice_id', $invoice->id)->latest('id')->first()?->deactivation_reason?->value)->toBe('paid');

    $this->post(route('invoices.void', $invoice), ['reason' => 'Mistake made'])->assertSessionHas('error');
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('applies late fees, disputes, resolves, writes off and voids by the state machine', function (): void {
    $this->client->forceFill(['late_fee_type' => 'percent_monthly', 'late_fee_rate_bp' => 150, 'late_fee_grace_days' => 10, 'late_fee_cap_bp' => 1500])->save();
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-07-31', '2026-08-31', [['Milestone', '1', 1240000]]);

    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('late_fee.amount', ['minor' => 26040, 'currency' => 'USD'])
        ->where('late_fee.chargeable_days', 42)
        ->where('late_fee.source', 'client'));

    $this->post(route('invoices.late-fee', $invoice), [])->assertRedirect();
    $invoice->refresh();
    expect($invoice->total_minor)->toBe(1266040)
        ->and($invoice->late_fee_applied_minor)->toBe(26040)
        ->and(InvoicePaymentLink::query()->where('invoice_id', $invoice->id)->where('active', true)->value('amount'))->toBe(1266040);

    $this->post(route('invoices.dispute', $invoice), ['reason' => 'Scope is disputed'])->assertRedirect();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Disputed)
        ->and($invoice->status_before_dispute)->toBe(InvoiceStatus::Open);

    $this->post(route('invoices.resolve-dispute', $invoice), ['resolution_note' => 'Agreed with client'])->assertRedirect();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Open);

    $this->post(route('invoices.write-off', $invoice), ['reason' => 'Client insolvent'])->assertRedirect();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Uncollectible)->and($invoice->risk_score)->toBeNull();

    $draft = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-22', '2026-11-22', issueIt: false);
    $this->post(route('invoices.void', $draft), ['reason' => 'Not needed'])->assertRedirect();
    expect($draft->refresh()->status)->toBe(InvoiceStatus::Void);

    $other = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-22', '2026-11-22', issueIt: false);
    $this->delete(route('invoices.destroy', $other))->assertRedirect(route('invoices.index'));
    expect(Invoice::withTrashed()->find($other->id)?->trashed())->toBeTrue();
});

it('validates invoice input and rejects duplicate numbers', function (): void {
    Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-01', '2026-10-30', number: 'INV-777');

    $this->post(route('invoices.store'), [...invoicePayload($this->client->id), 'number' => 'INV-777'])->assertSessionHasErrors('number');
    $this->post(route('invoices.store'), [...invoicePayload($this->client->id), 'due_date' => '2026-10-01'])->assertSessionHasErrors('due_date');
    $this->post(route('invoices.store'), [...invoicePayload($this->client->id), 'items' => [['description' => 'X', 'quantity' => '0', 'unit_price' => '1.00']]])->assertSessionHasErrors('items.0.quantity');
    $this->post(route('invoices.store'), [...invoicePayload($this->client->id), 'currency' => 'XYZ'])->assertSessionHasErrors('currency');
});

it('isolates invoices between workspaces and enforces abilities', function (): void {
    [$stranger, $otherWorkspace] = $this->member(WorkspaceRole::Owner);
    $foreignClient = Payflow::client($otherWorkspace);
    $foreign = Payflow::invoice($otherWorkspace, $stranger, $foreignClient, '2026-10-01', '2026-10-30');

    $this->get(route('invoices.show', $foreign))->assertNotFound();
    $this->post(route('invoices.payments.store', $foreign), ['amount' => '1.00', 'paid_on' => '2026-10-22', 'method' => 'cash'])->assertNotFound();

    [$member] = $this->member(WorkspaceRole::Member, $this->workspace);
    $own = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-01', '2026-10-30');
    $this->actingAs($member);

    $this->get(route('invoices.show', $own))->assertOk();
    $this->post(route('invoices.payments.store', $own), ['amount' => '1.00', 'paid_on' => '2026-10-22', 'method' => 'cash'])->assertForbidden();
    $this->post(route('invoices.void', $own), ['reason' => 'Because I can'])->assertForbidden();
    expect(Workspace::query()->count())->toBe(2);
});

it('enforces the monthly invoice limit of the plan', function (): void {
    $this->workspace->subscription()->delete();
    $this->workspace->unsetRelation('subscription');

    foreach (range(1, 10) as $index) {
        Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-01', '2026-10-30', issueIt: false);
    }

    $this->post(route('invoices.store'), invoicePayload($this->client->id, 'save_draft'))->assertSessionHas('error');
    expect(Invoice::query()->count())->toBe(10);
});
