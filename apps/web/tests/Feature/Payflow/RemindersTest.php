<?php

declare(strict_types=1);

use App\Domain\Collections\Dispatch\ReminderDispatcher;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Planning\SendSlot;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Enums\WorkspaceRole;
use App\Models\Delivery;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$this->user, $this->workspace] = $this->signInMember(WorkspaceRole::Owner);
    $this->workspace->forceFill(['timezone' => 'Europe/Riga'])->save();
    Payflow::pro($this->workspace);
    $this->client = Payflow::client($this->workspace, ['name' => 'Тестовый клиент', 'email' => 'ru-client@e2e.example', 'locale' => 'ru']);
});

function planNow(object $test, string $now): array
{
    Payflow::freeze($now);

    return $test->internal('POST', '/internal/v1/payflow/workspaces/'.$test->workspace->id.'/reminders/plan', [])->assertOk()->json();
}

it('plans the latest due step, supersedes older ones and caps the first contact at neutral', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-02', '2026-10-16');
    $result = planNow($this, '2026-10-22T10:00:00Z');

    $reminders = Reminder::query()->where('invoice_id', $invoice->id)->orderBy('step_offset_days')->get();
    $scheduled = $reminders->firstWhere('status', ReminderStatus::Scheduled);

    expect($result['created'])->toBe(1)
        ->and($result['superseded'])->toBe(2)
        ->and($reminders->where('status', ReminderStatus::Skipped)->pluck('step_offset_days')->all())->toBe([-3, 0])
        ->and($scheduled->step_offset_days)->toBe(4)
        ->and($scheduled->is_first_contact)->toBeTrue()
        ->and($scheduled->scheduled_for->toIso8601ZuluString())->toBe(CarbonImmutable::parse('2026-10-22T10:00:00Z')->toIso8601ZuluString())
        ->and($result['reminders'][0]['id'])->toBe($scheduled->id);

    $again = planNow($this, '2026-10-22T10:05:00Z');
    expect($again['created'])->toBe(0)->and(Reminder::query()->count())->toBe(3);
});

it('dispatches idempotently, renders the localized reminder with RFC 8058 headers and tracks deliveries', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-02', '2026-10-16');
    $plan = planNow($this, '2026-10-22T10:00:00Z');
    $reminder = Reminder::query()->findOrFail($plan['reminders'][0]['id']);

    $this->internal('POST', '/internal/v1/payflow/reminders/'.$reminder->id.'/dispatch', ['expected_scheduled_for' => '2026-10-22T09:00:00Z'])
        ->assertOk()->assertJson(['status' => 'rescheduled']);

    $this->internal('POST', '/internal/v1/payflow/reminders/'.$reminder->id.'/dispatch', ['expected_scheduled_for' => $plan['reminders'][0]['scheduled_for']])
        ->assertOk()->assertExactJson(['status' => 'queued']);

    $this->internal('POST', '/internal/v1/payflow/reminders/'.$reminder->id.'/dispatch', ['expected_scheduled_for' => $plan['reminders'][0]['scheduled_for']])
        ->assertOk()->assertJson(['status' => 'already_processed', 'current' => 'queued']);

    $email = Payflow::emails()[0];
    $reminder->refresh();

    expect(Payflow::emailPayloadErrors($email))->toBeNull()
        ->and($email['locale'])->toBe('ru')
        ->and($email['subject'])->toContain('Напоминание: срок оплаты счёта INV-2026-0001')
        ->and($email['subject'])->toContain('истёк 6 дней назад')
        ->and($email['headers']['List-Unsubscribe'])->toBe('<'.route('public.reminders.one-click', ['token' => $invoice->public_token]).'>')
        ->and($email['headers']['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($email['html'])->toContain('Отправлено')
        ->and($email['html'])->toContain('reminder-recipients')
        ->and($email['text'])->toContain('Уже оплатили')
        ->and($reminder->status)->toBe(ReminderStatus::Queued)
        ->and($invoice->refresh()->reminders_sent_count)->toBe(1);

    $this->internal('POST', '/internal/v1/deliveries', ['reference' => ['type' => 'reminder', 'id' => $reminder->id], 'channel' => 'email', 'status' => 'deferred', 'attempt' => 1, 'next_retry_at' => '2026-10-22T11:01:00Z', 'error' => '452 4.2.2 Mailbox full'])->assertCreated();
    expect($reminder->refresh()->status)->toBe(ReminderStatus::Deferred)->and($reminder->delivery_attempts)->toBe(1);

    $this->internal('POST', '/internal/v1/deliveries', ['reference' => ['type' => 'reminder', 'id' => $reminder->id], 'channel' => 'email', 'status' => 'failed', 'attempt' => 8, 'error' => '452 4.2.2 Mailbox full'])->assertCreated();
    expect($reminder->refresh()->status)->toBe(ReminderStatus::Failed)
        ->and(GuardAlert::query()->where('dedupe_key', 'rf:'.$reminder->id)->exists())->toBeTrue()
        ->and(Delivery::query()->count())->toBe(2);

    $this->post(route('reminders.retry', $reminder))->assertRedirect();
    expect($reminder->refresh()->status)->toBe(ReminderStatus::Queued)->and($reminder->attempts)->toBe(2);

    $this->internal('POST', '/internal/v1/deliveries', ['reference' => ['type' => 'reminder', 'id' => $reminder->id], 'channel' => 'email', 'status' => 'sent', 'provider_message_id' => 'msg-1'])->assertCreated();
    expect($reminder->refresh()->status)->toBe(ReminderStatus::Sent)
        ->and(GuardAlert::query()->where('dedupe_key', 'rf:'.$reminder->id)->value('status')?->value)->toBe('resolved');
});

it('reports too early and skips reminders that became ineligible', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-12', '2026-10-26');
    $plan = planNow($this, '2026-10-22T10:00:00Z');
    $reminder = Reminder::query()->where('invoice_id', $invoice->id)->where('status', 'scheduled')->sole();

    expect($reminder->step_offset_days)->toBe(-3)
        ->and($reminder->scheduled_for->toIso8601ZuluString())->toBe('2026-10-23T06:'.str_pad((string) SendSlot::spread($invoice->public_token), 2, '0', STR_PAD_LEFT).':00Z');

    $this->internal('POST', '/internal/v1/payflow/reminders/'.$reminder->id.'/dispatch', ['expected_scheduled_for' => $plan['reminders'][0]['scheduled_for']])
        ->assertOk()->assertJson(['status' => 'too_early']);

    $this->post(route('invoices.pause', $invoice), ['paused' => true])->assertRedirect();
    expect($reminder->refresh()->status)->toBe(ReminderStatus::Canceled)->and($reminder->policy_step_id)->toBeNull();

    $this->post(route('invoices.pause', $invoice), ['paused' => false])->assertRedirect();
    planNow($this, '2026-10-22T10:30:00Z');
    $replanned = Reminder::query()->where('invoice_id', $invoice->id)->where('status', 'scheduled')->sole();

    Payflow::freeze('2026-10-23T08:00:00Z');
    $invoice->forceFill(['status' => 'disputed'])->save();
    expect(app(ReminderDispatcher::class)->dispatch($replanned->id, CarbonImmutable::parse($replanned->scheduled_for), CarbonImmutable::parse('2026-10-23T08:00:00Z')))
        ->toBe(['status' => 'skipped', 'reason' => 'invoice_disputed']);
});

it('reschedules a reminder until the minimum interval after the previous one has passed', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-02', '2026-10-16');
    $plan = planNow($this, '2026-10-22T10:00:00Z');
    $reminder = Reminder::query()->findOrFail($plan['reminders'][0]['id']);
    $invoice->forceFill(['last_reminder_at' => '2026-10-20 12:30:00', 'reminders_sent_count' => 1])->save();

    $result = app(ReminderDispatcher::class)->dispatch($reminder->id, CarbonImmutable::parse($reminder->scheduled_for), CarbonImmutable::parse('2026-10-23T10:00:00Z'));

    expect($result['status'])->toBe('rescheduled')
        ->and(CarbonImmutable::parse($result['scheduled_for'] ?? '')->greaterThanOrEqualTo(CarbonImmutable::parse('2026-10-23T12:30:00Z')))->toBeTrue();
});

it('throttles first contacts to the configured hourly maximum', function (): void {
    WorkspaceProvisioner::settingsFor($this->workspace)->forceFill(['first_contact_max_per_hour' => 3, 'send_window_start' => '09:00', 'send_window_end' => '17:00'])->save();

    foreach (range(1, 7) as $index) {
        $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-12', '2026-10-26');
        $invoice->forceFill(['public_token' => 'token'.str_pad((string) $index, 30, 'x')])->save();
    }

    planNow($this, '2026-10-22T03:00:00Z');
    $hours = Reminder::query()->where('status', 'scheduled')->get()
        ->groupBy(fn (Reminder $reminder): string => $reminder->scheduled_for->copy()->utc()->format('Y-m-d H'))
        ->map->count()
        ->sortKeys()
        ->all();

    expect(array_values($hours))->toBe([3, 3, 1]);
});

it('previews and sends manual reminders with warnings and the recent-reminder guard', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-09-01', '2026-09-15');

    $this->postJson(route('invoices.reminders.preview', $invoice), ['tone' => 'firm'])
        ->assertOk()
        ->assertJsonPath('tone', 'firm')
        ->assertJsonPath('recipient', 'ru-client@e2e.example')
        ->assertJsonStructure(['tone', 'reasons', 'subject', 'body_markdown', 'body_html', 'recipient', 'cc', 'late_fee', 'warnings']);

    $this->post(route('invoices.reminders.store', $invoice), ['tone' => 'neutral', 'body_markdown' => 'Без ссылки'])->assertSessionHas('error');
    $this->post(route('invoices.reminders.store', $invoice), ['tone' => 'neutral'])->assertSessionHas('success');
    $this->post(route('invoices.reminders.store', $invoice), ['tone' => 'neutral'])->assertSessionHas('error');
    $this->post(route('invoices.reminders.store', $invoice), ['tone' => 'firm', 'force' => true, 'subject' => 'Счёт {{invoice_number}}', 'body_markdown' => 'Оплатите: {{invoice_link}}'])->assertSessionHas('success');

    $emails = Payflow::emails();
    expect($emails)->toHaveCount(2)
        ->and($emails[1]['subject'])->toBe('Счёт INV-2026-0001')
        ->and(Reminder::query()->where('kind', 'manual')->count())->toBe(2);

    $this->get(route('reminders.index', ['tab' => 'sent']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reminders/Index')
        ->where('tab', 'sent')
        ->has('reminders.data', 2)
        ->where('counts.sent', 2)
        ->has('stats'));
});

it('lets recipients report payment, stop reminders and unsubscribe in one click without CSRF', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-02', '2026-10-16');
    planNow($this, '2026-10-22T10:00:00Z');
    auth()->logout();

    $this->get(route('public.invoices.reminders', ['token' => $invoice->public_token]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page->component('public/ReminderChoices')->where('opted_out', false)->has('legal'));

    $this->post(route('public.invoices.reminders.paid', ['token' => $invoice->public_token]), ['paid_on' => '2026-10-21', 'reference' => 'SEPA-42'])->assertRedirect();
    $invoice->refresh();

    expect($invoice->client_reported_reference)->toBe('SEPA-42')
        ->and($invoice->reminders_paused)->toBeTrue()
        ->and($invoice->reminders_pause_source?->value)->toBe('client_reported_paid')
        ->and($invoice->status->value)->toBe('open')
        ->and(Reminder::query()->where('status', 'scheduled')->count())->toBe(0)
        ->and(GuardAlert::query()->where('dedupe_key', 'crp:'.$invoice->id)->exists())->toBeTrue();

    $this->call('POST', route('public.reminders.one-click', ['token' => $invoice->public_token]), ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertContent('');
    $client = $this->client->refresh();

    expect($client->isOptedOut())->toBeTrue()
        ->and(GuardAlert::query()->where('dedupe_key', 'opt:'.$client->id)->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('topic', 'telegram.notify')->orWhere('topic', 'email.send')->count())->toBeGreaterThan(0);

    $this->post(route('public.invoices.reminders.stop', ['token' => $invoice->public_token]), ['note' => 'again'])->assertRedirect();

    $this->actingAs($this->user);
    $this->post(route('clients.pause', $client), ['paused' => false])->assertSessionHas('error');
    $this->post(route('clients.pause', $client), ['paused' => false, 'acknowledge_opt_out' => true])->assertSessionHas('success');
    expect($client->refresh()->reminders_paused)->toBeFalse();

    $this->get('/i/unknownTokenThatDoesNotExist123')->assertNotFound();
});

it('keeps planning nothing for paid invoices and resolves escalations', function (): void {
    $invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-07-01', '2026-07-15');
    $invoice->forceFill(['reminders_sent_count' => 2, 'last_reminder_at' => '2026-09-01 09:00:00'])->save();
    planNow($this, '2026-10-22T10:00:00Z');

    expect(GuardAlert::query()->where('dedupe_key', 'esc:'.$invoice->id)->exists())->toBeTrue();

    $this->post(route('invoices.payments.store', $invoice), ['amount' => '1000.00', 'paid_on' => '2026-10-22', 'method' => 'bank_transfer'])->assertRedirect();
    expect(Invoice::query()->find($invoice->id)?->status->value)->toBe('paid')
        ->and(Reminder::query()->where('invoice_id', $invoice->id)->where('status', 'scheduled')->count())->toBe(0)
        ->and(GuardAlert::query()->where('dedupe_key', 'esc:'.$invoice->id)->value('status')?->value)->toBe('resolved');

    expect(planNow($this, '2026-10-22T11:00:00Z')['created'])->toBe(0);
});
