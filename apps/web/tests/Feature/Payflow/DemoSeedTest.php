<?php

declare(strict_types=1);

use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Planning\SendSlot;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Receivables\LateFees\LateFees;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Payflow\NorthstarGuardSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds Northstar Digital and the E2E workspaces with the contract facts', function (): void {
    Storage::fake('local');
    Storage::fake('documents');
    $this->seed(DatabaseSeeder::class);

    $northstar = Workspace::query()->where('slug', 'demo-studio')->sole();
    $today = WorkspaceClock::todayIn('Europe/Riga', CarbonImmutable::now())->toDateString();

    app(CurrentWorkspace::class)->runAs($northstar, function () use ($northstar, $today): void {
        $settings = WorkspaceProvisioner::settingsFor($northstar);
        $helios = Client::query()->where('name', 'Helios Media Group')->sole();
        $orbit = Client::query()->where('name', 'Orbit Fintech')->sole();
        $anchor = Invoice::query()->where('number', 'NS-2026-0118')->sole();
        $baltic = Invoice::query()->where('number', 'NS-2026-0145')->sole();
        $scheduled = Reminder::query()->where('invoice_id', $anchor->id)->where('status', 'scheduled')->sole();

        expect($northstar->name)->toBe('Northstar Digital')
            ->and($northstar->timezone)->toBe('Europe/Riga')
            ->and($settings->invoice_prefix)->toBe('NS-')
            ->and($settings->next_invoice_seq)->toBe(155)
            ->and($settings->require_second_factor)->toBeFalse()
            ->and($helios->late_payer_class->value)->toBe('chronic')
            ->and($helios->avgDaysLate())->toBe(18.38)
            ->and($orbit->late_payer_class->value)->toBe('occasional')
            ->and($orbit->timezone)->toBe('America/New_York')
            ->and(Client::query()->where('name', 'Brightpath Health')->value('late_payer_class')?->value)->toBe('reliable')
            ->and(Client::query()->where('name', 'Kraft & Hale Architects')->value('late_payer_class')?->value)->toBe('new')
            ->and($anchor->total_minor)->toBe(1240000)
            ->and($anchor->reminders_sent_count)->toBe(6)
            ->and($anchor->risk_band?->value)->toBe('high')
            ->and(LateFees::suggest($anchor, $today)?->amountMinor)->toBe(26040)
            ->and(LateFees::suggest($baltic, $today)?->amountMinor)->toBe(2695)
            ->and(ToneContext::suggest($anchor, $today)->tone->value)->toBe('final')
            ->and($scheduled->tone->value)->toBe('final')
            ->and($scheduled->step_offset_days)->toBe(45)
            ->and((int) $scheduled->scheduled_for->copy()->setTimezone('Europe/Riga')->format('i'))->toBe(SendSlot::spread($anchor->public_token))
            ->and(Invoice::query()->where('number', 'NS-2026-0131')->value('status')?->value)->toBe('partially_paid')
            ->and(Invoice::query()->where('number', 'NS-2026-0142')->value('status')?->value)->toBe('disputed')
            ->and(Invoice::query()->where('number', 'NS-2026-0153')->value('status')?->value)->toBe('paid')
            ->and(Invoice::query()->whereNull('number')->value('status')?->value)->toBe('draft')
            ->and(Invoice::query()->where('number', 'like', 'NS-%')->count())->toBe(52)
            ->and(InvoicePaymentLink::query()->where('active', true)->whereIn('invoice_id', Invoice::query()->where('number', 'NS-2026-0142')->select('id'))->exists())->toBeFalse()
            ->and(InvoicePaymentLink::query()->where('active', true)->whereIn('invoice_id', Invoice::query()->where('number', 'NS-2026-0151')->select('id'))->exists())->toBeTrue()
            ->and(GuardAlert::query()->where('dedupe_key', 'esc:'.$anchor->id)->exists())->toBeTrue()
            ->and(GuardAlert::query()->where('type', 'reminder_failed')->exists())->toBeTrue()
            ->and($helios->contract_document_id)->not->toBeNull();
    });

    $dmitry = User::query()->where('email', 'approver@payflow.test')->sole();
    $admin = User::query()->where('email', 'admin@payflow.test')->sole();
    $lab = Workspace::query()->where('name', 'E2E Lab')->sole();
    $labSettings = WorkspaceProvisioner::settingsFor($lab);

    expect($dmitry->roleIn($northstar))->toBe(WorkspaceRole::Approver)
        ->and($admin->roleIn($northstar))->toBe(WorkspaceRole::Member)
        ->and($admin->is_platform_admin)->toBeTrue()
        ->and($labSettings->business_days_only)->toBeFalse()
        ->and($labSettings->require_second_factor)->toBeTrue()
        ->and(Client::query()->forWorkspace($lab)->count())->toBe(2)
        ->and(User::query()->where('email', 'e2e-approver@payflow.test')->value('two_factor_confirmed_at'))->not->toBeNull()
        ->and(Workspace::query()->where('name', 'E2E Solo')->exists())->toBeTrue();

    app(CurrentWorkspace::class)->runAs($northstar, function () use ($northstar): void {
        $year = CarbonImmutable::parse(WorkspaceClock::todayIn('Europe/Riga', CarbonImmutable::now()))->format('Y');
        $payout = fn (int $seq): PayoutRequest => PayoutRequest::query()->where('number', 'PO-'.$year.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT))->sole();
        $pixel = Vendor::query()->where('name', 'PixelForge Studio')->sole();
        $change = VendorBankAccount::query()->where('vendor_id', $pixel->id)->where('version', 2)->sole();
        $codes = array_map(fn (array $signal): string => $signal['code'], $change->risk_signals);
        sort($codes);
        $expected = ['country_changed', 'holder_name_mismatch', 'lookalike_domain', 'new_contact_in_request', 'pending_payout', 'request_via_email', 'secrecy_language', 'urgency_language'];

        expect($change->status->value)->toBe('pending_verification')
            ->and($change->risk_score)->toBe(100)
            ->and($change->risk_band->value)->toBe('high')
            ->and($codes)->toBe($expected)
            ->and(array_sum(array_map(fn (array $signal): int => $signal['points'], $change->risk_signals)))->toBe(145)
            ->and($change->requester_email_domain)->toBe('pixelf0rge.example')
            ->and($change->change_request_text)->toBe(NorthstarGuardSeeder::PIXELFORGE_REQUEST)
            ->and($payout(87)->status->value)->toBe('on_hold')
            ->and($payout(87)->hold_reason?->value)->toBe('bank_details_changed')
            ->and(PayoutApproval::query()->where('payout_request_id', $payout(87)->id)->whereNotNull('invalidated_at')->count())->toBe(1)
            ->and($payout(88)->status->value)->toBe('rejected')
            ->and($payout(89)->status->value)->toBe('pending_approval')
            ->and($payout(89)->risk_score)->toBe(30)
            ->and($payout(89)->risk_band?->value)->toBe('medium')
            ->and($payout(89)->required_approvals)->toBe(1)
            ->and($payout(89)->telegram_allowed)->toBeTrue()
            ->and($payout(90)->status->value)->toBe('approved')
            ->and($payout(90)->auto_approved)->toBeTrue()
            ->and($payout(90)->release_requires_other_user)->toBeTrue()
            ->and($payout(90)->auto_approve_basis)->toEqual(['threshold' => 50000, 'cumulative_used' => 32000, 'cumulative_cap' => 100000])
            ->and($payout(91)->status->value)->toBe('approved')
            ->and($payout(92)->status->value)->toBe('pending_approval')
            ->and($payout(92)->required_approvals)->toBe(2)
            ->and($payout(92)->approvals_count)->toBe(1)
            ->and(GuardAlert::query()->where('status', '!=', 'resolved')->pluck('type')->map(fn ($type): string => $type->value)->unique()->sort()->values()->all())
            ->toBe(['bank_change_high_risk', 'bank_change_requested', 'collections_escalation', 'payout_on_hold', 'payout_pending_approval', 'reminder_failed'])
            ->and(GuardAlert::query()->where('type', 'payout_pending_approval')->where('status', 'open')->count())->toBe(2)
            ->and(WorkspaceProvisioner::settingsFor($northstar)->next_payout_seq)->toBe(93);
    });
});
