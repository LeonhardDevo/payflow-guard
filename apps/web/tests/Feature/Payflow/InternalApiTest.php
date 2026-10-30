<?php

declare(strict_types=1);

use App\Domain\Collections\Models\Reminder;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Storage::fake('local');
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$this->user, $this->workspace] = $this->member(WorkspaceRole::Owner);
    Payflow::pro($this->workspace);
    $this->client = Payflow::client($this->workspace, ['name' => 'Helios Media Group', 'legal_name' => 'Helios Media Group Inc.']);
    $this->invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-07-31', '2026-08-31', [['Milestone', '1', 1240000]], number: 'NS-2026-0118');
});

it('routes internal payflow endpoints with signatures and caller groups', function (): void {
    expect(route('internal.payflow.reminders.dispatch', 5, false))->toBe('/internal/v1/payflow/reminders/5/dispatch');

    $this->internal('POST', '/internal/v1/payflow/workspaces/'.$this->workspace->id.'/reminders/plan', [])->assertOk();
    $this->call('POST', '/internal/v1/payflow/workspaces/'.$this->workspace->id.'/reminders/plan', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertUnauthorized();
    $this->internal('POST', '/internal/v1/payflow/workspaces/'.$this->workspace->id.'/reminders/plan', [], 'ai')->assertForbidden()->assertJson(['error' => 'caller_not_allowed']);
    $this->internal('GET', '/internal/v1/payflow/ai/ar/summary?workspace_id='.$this->workspace->id.'&user_id='.$this->user->id.'&locale=en', null, 'worker')->assertForbidden();
});

it('lists active workspaces, recomputes risk, serves PDFs and purges retention data', function (): void {
    $this->internal('GET', '/internal/v1/payflow/workspaces?active=1')->assertOk()->assertJsonFragment(['id' => $this->workspace->id, 'timezone' => 'UTC']);

    $this->internal('POST', '/internal/v1/payflow/workspaces/'.$this->workspace->id.'/risk/recompute', [])
        ->assertOk()
        ->assertJsonStructure(['clients', 'invoices', 'band_changes', 'duration_ms']);

    $this->internal('GET', '/internal/v1/payflow/invoices/'.$this->invoice->id.'/pdf?workspace_id='.$this->workspace->id)->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->internal('GET', '/internal/v1/payflow/invoices/'.$this->invoice->id.'/pdf?workspace_id=999999')->assertNotFound();

    $reminder = new Reminder;
    $reminder->forceFill([
        'workspace_id' => $this->workspace->id, 'invoice_id' => $this->invoice->id, 'client_id' => $this->client->id, 'kind' => 'manual',
        'tone' => 'neutral', 'locale' => 'en', 'scheduled_for' => '2025-01-01 10:00:00', 'status' => 'sent', 'body_html' => '<p>old</p>', 'body_text' => 'old',
    ])->save();
    $reminder->forceFill(['created_at' => '2025-01-01 10:00:00'])->save();

    $purged = $this->internal('POST', '/internal/v1/payflow/maintenance/purge', [])->assertOk()->json('purged');

    expect($purged['reminder_bodies'])->toBe(1)
        ->and($reminder->refresh()->body_html)->toBeNull()
        ->and(AuditLog::query()->where('action', 'payflow.retention.purged')->exists())->toBeTrue();
});

it('sends the daily digest once per workspace at the configured local hour', function (): void {
    Payflow::freeze('2026-10-22T09:05:00Z');

    $first = $this->internal('POST', '/internal/v1/payflow/digests/run', [])->assertOk()->json();
    $second = $this->internal('POST', '/internal/v1/payflow/digests/run', [])->assertOk()->json();

    $message = OutboxMessage::query()->where('topic', 'email.send')->where('payload->reference->type', 'notification_digest')->latest('created_at')->first();

    expect($first)->toBe(['workspaces' => 1, 'messages' => 1])
        ->and($second)->toBe(['workspaces' => 0, 'messages' => 0])
        ->and($message?->payload['text'] ?? '')->toContain('NS-2026-0118')
        ->and($message?->payload['text'] ?? '')->toContain('$12,400.00');

    Payflow::freeze('2026-10-22T11:05:00Z');
    expect($this->internal('POST', '/internal/v1/payflow/digests/run', [])->json('workspaces'))->toBe(0);
});

it('honours the now override only outside production with dev routes', function (): void {
    $later = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-22', '2026-11-30');

    config(['payflow.allow_clock_override' => false]);
    $this->internal('POST', '/internal/v1/payflow/workspaces/'.$this->workspace->id.'/reminders/plan', ['now' => '2026-12-02T12:00:00Z'])->assertOk();
    expect(Reminder::query()->where('invoice_id', $later->id)->exists())->toBeFalse();

    config(['payflow.allow_clock_override' => true]);
    $this->internal('POST', '/internal/v1/payflow/workspaces/'.$this->workspace->id.'/reminders/plan', ['now' => '2026-12-02T12:00:00Z'])->assertOk();

    expect(Reminder::query()->where('invoice_id', $later->id)->where('status', 'scheduled')->value('scheduled_for')?->toIso8601ZuluString())->toBe('2026-12-02T12:00:00Z');
});

it('answers AI data tools for members only, resolving clients by fuzzy names', function (): void {
    $query = fn (string $path, array $params, ?int $userId = null): string => '/internal/v1/payflow/ai/'.$path.'?'.http_build_query([
        'workspace_id' => $this->workspace->id, 'user_id' => $userId ?? $this->user->id, 'locale' => 'en', ...$params,
    ]);

    $this->internal('GET', $query('invoices/overdue', ['limit' => 5]), null, 'ai')
        ->assertOk()
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.items.0.number', 'NS-2026-0118')
        ->assertJsonPath('data.items.0.balance_due.formatted', '$12,400.00')
        ->assertJsonStructure(['data', 'summary', 'as_of']);

    $this->internal('GET', $query('invoices/lookup', ['number' => 'ns-2026-0118']), null, 'ai')->assertOk()->assertJsonPath('data.days_overdue', 52);
    $this->internal('GET', $query('clients/profile', ['client_query' => 'helios media']), null, 'ai')->assertOk()->assertJsonPath('resolved.name', 'Helios Media Group');
    $this->internal('GET', $query('clients/terms', ['client_query' => 'Helios Media Group']), null, 'ai')->assertOk()->assertJsonPath('data.late_fee.source', 'workspace');
    $this->internal('GET', $query('reminders/next', ['invoice_number' => 'NS-2026-0118']), null, 'ai')->assertOk()->assertJsonStructure(['data' => ['items', 'recommendation' => ['code', 'text']]]);
    $this->internal('GET', $query('ar/summary', []), null, 'ai')->assertOk()->assertJsonPath('data.outstanding.minor', 1240000);
    $this->internal('GET', $query('clients/late-payers', []), null, 'ai')->assertOk();
    $this->internal('GET', $query('clients/profile', ['client_query' => 'Nonexistent Corp']), null, 'ai')->assertOk()->assertJsonPath('error', 'not_found');

    [$stranger] = $this->member(WorkspaceRole::Owner);
    $this->internal('GET', $query('invoices/overdue', [], $stranger->id), null, 'ai')->assertForbidden()->assertJsonPath('error', 'not_a_member');

    [$member] = $this->member(WorkspaceRole::ExternalApprover, $this->workspace);
    $this->internal('GET', $query('invoices/overdue', [], $member->id), null, 'ai')->assertForbidden()->assertJsonPath('error', 'forbidden');

    $this->workspace->forceFill(['ai_external_allowed' => true])->save();
    $this->client->forceFill(['phone' => '+1 555 010 0199'])->save();
    $response = $this->internal('GET', $query('clients/profile', ['client_query' => 'Helios']), null, 'ai')->assertOk();
    expect(json_encode($response->json()))->not->toContain('555 010 0199');
});
