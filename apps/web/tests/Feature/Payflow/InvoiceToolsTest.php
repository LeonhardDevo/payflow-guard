<?php

declare(strict_types=1);

use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Receivables\Models\Invoice;
use App\Enums\WorkspaceRole;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$this->user, $this->workspace] = $this->signInMember(WorkspaceRole::Owner);
    Payflow::pro($this->workspace);
    $this->client = Payflow::client($this->workspace, ['name' => 'Bulk Client']);
});

it('runs bulk actions and reports done and skipped counts', function (): void {
    $draft = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-20', '2026-11-20', issueIt: false);
    $open = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-01', '2026-10-15');

    $this->post(route('invoices.bulk'), ['action' => 'mark_sent', 'ids' => [$draft->id, $open->id, 999999]])
        ->assertSessionHas('success', __('payflow.flash.bulk_done', ['done' => 1, 'skipped' => 2]));
    expect($draft->refresh()->status->value)->toBe('open');

    $this->post(route('invoices.bulk'), ['action' => 'pause', 'ids' => [$draft->id, $open->id]])->assertSessionHas('success');
    expect(Invoice::query()->where('reminders_paused', true)->count())->toBe(2);

    $this->post(route('invoices.bulk'), ['action' => 'resume', 'ids' => [$draft->id, $open->id]])->assertSessionHas('success');
    $this->post(route('invoices.bulk'), ['action' => 'remind', 'ids' => [$open->id]])->assertSessionHas('success');
    expect(Reminder::query()->where('kind', 'manual')->count())->toBe(1);

    $this->post(route('invoices.bulk'), ['action' => 'delete', 'ids' => [$open->id]])->assertSessionHasErrors('action');
});

it('exports invoices as CSV, lists client options and confirms first contact', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-01', '2026-10-15');

    $csv = $this->get(route('invoices.export', ['status' => 'overdue']))->assertOk()->streamedContent();
    expect($csv)->toContain('number,client,status')->and($csv)->toContain('INV-2026-0001,"Bulk Client",open,overdue');

    $this->getJson(route('clients.options', ['q' => 'bulk']))->assertOk()->assertJsonPath('0.name', 'Bulk Client')->assertJsonPath('0.payment_terms_days', 14);

    $this->post(route('invoices.confirm-first-contact', $invoice))->assertSessionHas('success');
    expect($invoice->refresh()->first_contact_confirmed_by)->toBe($this->user->id);
});

it('acknowledges a reported payment and resumes reminders', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-01', '2026-10-15');
    $this->post(route('public.invoices.reminders.paid', ['token' => $invoice->public_token]), ['reference' => 'REF-1'])->assertRedirect();

    $this->post(route('invoices.acknowledge-reported-payment', $invoice), ['resume_reminders' => true])->assertSessionHas('success');

    expect($invoice->refresh()->reminders_paused)->toBeFalse()
        ->and(GuardAlert::query()->where('dedupe_key', 'crp:'.$invoice->id)->value('status')?->value)->toBe('resolved');
});
