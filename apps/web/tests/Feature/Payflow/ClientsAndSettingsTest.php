<?php

declare(strict_types=1);

use App\Domain\Collections\Models\MessageTemplate;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Receivables\Models\Client;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$this->user, $this->workspace] = $this->signInMember(WorkspaceRole::Owner);
    Payflow::pro($this->workspace);
});

function clientPayload(array $overrides = []): array
{
    return [
        'name' => 'Helios Media Group',
        'legal_name' => 'Helios Media Group Inc.',
        'contact_name' => 'Rachel Moore',
        'email' => 'ap@helios-media.example',
        'cc_emails' => ['cfo@helios-media.example'],
        'country' => 'US',
        'timezone' => 'America/New_York',
        'locale' => 'en',
        'currency' => 'USD',
        'payment_terms_days' => 30,
        'tone_ceiling' => 'none',
        'late_fee_override' => true,
        'late_fee' => ['type' => 'percent_monthly', 'rate' => '1.5', 'grace_days' => 10, 'cap' => '15'],
        'tags' => ['msa'],
        ...$overrides,
    ];
}

it('provisions settings, the default policy and templates for new workspaces', function (): void {
    $settings = WorkspaceProvisioner::settingsFor($this->workspace);
    $policy = ReminderPolicy::query()->with('steps')->findOrFail($settings->default_reminder_policy_id);

    expect($settings->invoice_prefix)->toBe('INV-')
        ->and($policy->is_default)->toBeTrue()
        ->and($policy->steps->pluck('offset_days')->all())->toBe([-3, 0, 4, 9, 14, 21, 30, 45])
        ->and($policy->steps->last()->tone->value)->toBe('final')
        ->and(MessageTemplate::query()->count())->toBe(12);
});

it('creates, shows, updates and deletes clients with late-fee overrides', function (): void {
    $this->post(route('clients.store'), clientPayload())->assertRedirect();
    $client = Client::query()->sole();

    expect($client->late_fee_type?->value)->toBe('percent_monthly')
        ->and($client->late_fee_rate_bp)->toBe(150)
        ->and($client->late_fee_cap_bp)->toBe(1500)
        ->and($client->cc_emails)->toBe(['cfo@helios-media.example']);

    $this->get(route('clients.show', $client))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('clients/Show')
        ->has('client', fn (Assert $detail) => $detail
            ->where('name', 'Helios Media Group')
            ->where('late_payer_class', 'new')
            ->where('effective_timezone', 'America/New_York')
            ->where('effective_payment_terms_days', 30)
            ->where('late_fee.type', 'percent_monthly')
            ->where('late_fee.inherited', false)
            ->where('reminder_policy.inherited', true)
            ->where('open_balance', ['minor' => 0, 'currency' => 'USD'])
            ->etc())
        ->where('can', fn ($can): bool => collect($can)->get('edit') === true && collect($can)->get('clients.view') === true && collect($can)->get('invoices.view') === true)
        ->has('invoices')
        ->has('behaviour')
        ->has('reminders')
        ->has('timeline')
        ->where('can.edit', true));

    $this->post(route('clients.store'), clientPayload(['name' => 'helios media group']))->assertSessionHas('error');

    $this->put(route('clients.update', $client), clientPayload(['tone_ceiling' => 'neutral', 'late_fee_override' => false]))->assertRedirect();
    expect($client->refresh()->tone_ceiling->value)->toBe('neutral')->and($client->late_fee_type)->toBeNull()
        ->and(AuditLog::query()->where('action', 'payflow.client.updated')->exists())->toBeTrue();

    $this->get(route('clients.index', ['class' => 'new']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('clients/Index')
        ->has('clients.data', 1)
        ->where('class_counts.new', 1)
        ->has('owners'));

    Payflow::invoice($this->workspace, $this->user, $client, '2026-10-01', '2026-10-31');
    $this->delete(route('clients.destroy', $client))->assertSessionHas('error');
    expect(Client::query()->count())->toBe(1);
});

it('anonymises a client only without open invoices and only after step-up', function (): void {
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $client = Payflow::client($this->workspace, ['name' => 'Ivan Smirnov', 'phone' => '+995 555 12 34 56']);

    $this->post(route('clients.anonymise', $client), ['confirm_name' => 'Ivan Smirnov', 'natural_person' => true])->assertSessionHas('error');

    $this->withSession(['payflow.stepup_at' => now()->getTimestamp(), 'payflow.stepup_method' => 'totp'])
        ->post(route('clients.anonymise', $client), ['confirm_name' => 'Ivan Smirnov', 'natural_person' => true])
        ->assertRedirect();

    $client->refresh();
    expect($client->anonymised_at)->not->toBeNull()
        ->and($client->email)->toBeNull()
        ->and($client->phone)->toBeNull()
        ->and($client->name)->toBe('Anonymised client #'.$client->id);
});

it('updates collections settings, policies and templates with validation and audit', function (): void {
    $this->get(route('settings.collections'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Collections')
        ->has('settings', fn (Assert $settings) => $settings->where('invoice_prefix', 'INV-')->where('send_window_start', '09:00')->etc())
        ->has('policies', 1)
        ->has('templates', 12)
        ->where('can_edit_templates', true)
        ->has('template_variables'));

    $this->put(route('settings.collections.update'), [
        'invoice_prefix' => 'NS-',
        'default_payment_terms_days' => 30,
        'send_window_start' => '08:00',
        'send_window_end' => '18:00',
        'business_days_only' => true,
        'first_contact_max_per_hour' => 20,
        'track_invoice_views' => false,
        'late_fee' => ['type' => 'percent_daily', 'rate' => '0.1', 'grace_days' => 0, 'cap' => '10'],
        'company' => ['legal_name' => 'Northstar Digital SIA'],
        'digest_enabled' => true,
        'digest_hour' => 9,
    ])->assertSessionHas('success');

    $settings = WorkspaceProvisioner::settingsFor($this->workspace);
    expect($settings->invoice_prefix)->toBe('NS-')
        ->and($settings->late_fee_rate_bp)->toBe(10)
        ->and($settings->track_invoice_views)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'payflow.settings.collections_updated')->exists())->toBeTrue();

    $this->put(route('settings.collections.update'), ['invoice_prefix' => 'bad prefix'])->assertSessionHasErrors(['invoice_prefix', 'send_window_start']);

    $steps = [
        ['offset_days' => 0, 'tone' => 'auto', 'attach_pdf' => true, 'include_payment_link' => true, 'include_late_fee_notice' => false, 'notify_owner' => false],
        ['offset_days' => 7, 'tone' => 'firm', 'attach_pdf' => true, 'include_payment_link' => true, 'include_late_fee_notice' => true, 'notify_owner' => true],
    ];
    $this->post(route('settings.policies.store'), ['name' => 'Gentle', 'max_reminders' => 4, 'min_interval_days' => 5, 'escalate_after_days' => 45, 'steps' => $steps])->assertSessionHas('success');
    $gentle = ReminderPolicy::query()->where('name', 'Gentle')->firstOrFail();
    expect($gentle->steps()->count())->toBe(2);

    $this->put(route('settings.policies.update', $gentle), ['name' => 'Gentle', 'max_reminders' => 4, 'min_interval_days' => 5, 'escalate_after_days' => 45, 'is_default' => true, 'steps' => [$steps[1]]])->assertSessionHas('success');
    expect($gentle->refresh()->is_default)->toBeTrue()
        ->and($gentle->steps()->pluck('offset_days')->all())->toBe([7])
        ->and(WorkspaceProvisioner::settingsFor($this->workspace)->default_reminder_policy_id)->toBe($gentle->id);

    $this->delete(route('settings.policies.destroy', $gentle))->assertSessionHas('error');

    $template = MessageTemplate::query()->where('key', 'reminder_firm')->where('locale', 'en')->firstOrFail();
    $this->put(route('settings.templates.update', $template), ['subject' => 'Pay {{invoice_number}}', 'body_markdown' => 'Use {{iban}}'])->assertSessionHas('error');
    $this->put(route('settings.templates.update', $template), ['subject' => 'Pay {{invoice_number}}', 'body_markdown' => 'Please pay {{amount_due}}: {{invoice_link}}'])->assertSessionHas('success');
    expect($template->refresh()->is_customized)->toBeTrue();

    $this->postJson(route('settings.templates.preview'), ['key' => 'reminder_firm', 'locale' => 'ru', 'subject' => 'Счёт {{invoice_number}}', 'body_markdown' => 'Оплатите {{amount_due}} {{nope}}'])
        ->assertOk()
        ->assertJsonPath('unknown_variables', ['nope']);

    $this->post(route('settings.templates.reset', $template))->assertSessionHas('success');
    expect($template->refresh()->is_customized)->toBeFalse();
});

it('saves notification preferences limited to the role events', function (): void {
    $this->get(route('settings.notifications'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Notifications')
        ->has('preferences')
        ->has('available_events')
        ->where('forced_events', ['bank_change_high_risk', 'guard_settings_changed', 'guard_role_granted', 'receiving_details_change', 'payment_account_issue'])
        ->where('telegram_linked', false));

    $this->put(route('settings.notifications.update'), ['telegram_events' => ['payment_received'], 'email_events' => ['daily_digest'], 'digest_channel' => 'email', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00'])
        ->assertSessionHas('success');
    $this->put(route('settings.notifications.update'), ['telegram_events' => ['nope'], 'email_events' => [], 'digest_channel' => 'email'])->assertSessionHasErrors('telegram_events.0');
});

it('connects the demo payment account and disconnects it', function (): void {
    $this->get(route('settings.payments'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Payments')
        ->where('account', null)
        ->where('market', 'intl')
        ->where('demo_allowed', true)
        ->where('money_flow_notice', true));

    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->post(route('settings.payments.demo'))->assertSessionHas('success');
    $this->get(route('settings.payments'))->assertInertia(fn (Assert $page) => $page->where('account.driver', 'fake')->where('account.status', 'active')->where('account.mode', 'test'));

    $this->post(route('settings.payments.stripe'), ['restricted_key' => 'sk_test_abcdefghijklmnopqrstuvwxyz'])->assertSessionHas('error');

    $this->withSession(['payflow.stepup_at' => now()->getTimestamp(), 'payflow.stepup_method' => 'totp'])
        ->post(route('settings.payments.stripe'), ['restricted_key' => 'sk_test_abcdefghijklmnopqrstuvwxyz'])
        ->assertSessionHas('error');

    $this->withSession(['payflow.stepup_at' => now()->getTimestamp(), 'payflow.stepup_method' => 'totp'])
        ->delete(route('settings.payments.disconnect'))
        ->assertSessionHas('success');
    expect(AuditLog::query()->where('action', 'payflow.payment_account.disconnected')->exists())->toBeTrue();
});

it('forbids collections settings for members and keeps tenancy for policies', function (): void {
    [$member] = $this->member(WorkspaceRole::Member, $this->workspace);
    [, $other] = $this->member(WorkspaceRole::Owner);
    $foreignPolicy = ReminderPolicy::query()->forWorkspace(WorkspaceProvisioner::settingsFor($other)->workspace_id)->firstOrFail();

    $this->put(route('settings.policies.update', $foreignPolicy), ['name' => 'X'])->assertNotFound();

    $this->actingAs($member)->get(route('settings.collections'))->assertForbidden();
    expect(Workspace::query()->count())->toBe(2);
});
