<?php

declare(strict_types=1);

use App\Billing\PlanLimitExceeded;
use App\Billing\PlanLimits;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Recording\PaymentRecorder;
use App\Domain\Privacy\PayflowExportContributor;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\Clock;
use App\Enums\WorkspaceRole;
use App\Http\Middleware\Payflow\ApplyClockOverride;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\AccountDeletion;
use App\Workspaces\WorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Payflow\Support\Payflow;

it('grants exactly the abilities of the contract matrix to every role', function (): void {
    $matrix = Payflow::fixture('permission-matrix.json');

    expect(config('permissions.abilities'))->toEqualCanonicalizing(array_keys($matrix['abilities']));

    foreach ($matrix['roles'] as $roleValue) {
        $role = WorkspaceRole::from($roleValue);

        foreach ($matrix['abilities'] as $ability => $roles) {
            expect($role->allows($ability))->toBe(in_array($roleValue, $roles, true), "{$roleValue} {$ability}");
        }

        expect($role->isGuardPrivileged())->toBe(in_array($roleValue, $matrix['guard_privileged'], true), "{$roleValue} privileged")
            ->and(array_map(fn (WorkspaceRole $assignable): string => $assignable->value, $role->assignableRoles()))->toBe($matrix['assignable'][$roleValue], "{$roleValue} assignable");
    }
});

it('counts seats without external approvers and exposes domain limits and entitlements', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    $this->member(WorkspaceRole::ExternalApprover, $workspace);
    WorkspaceInvitation::query()->create([
        'workspace_id' => $workspace->id, 'email' => 'ext@example.com', 'role' => WorkspaceRole::ExternalApprover,
        'token_hash' => WorkspaceInvitation::hashToken(Str::random(64)), 'invited_by' => $owner->id, 'expires_at' => now()->addDays(7),
    ]);
    $limits = app(PlanLimits::class);

    expect($limits->used($workspace, 'seats'))->toBe(1)
        ->and($limits->used($workspace, 'external_approvers'))->toBe(2)
        ->and($limits->limit($workspace, 'clients'))->toBe(5)
        ->and($limits->hasFeature($workspace, 'payment_guard'))->toBeTrue()
        ->and($limits->hasFeature($workspace, 'csv_import'))->toBeFalse()
        ->and(array_keys($limits->usage($workspace)))->toContain('invoices_per_month', 'reminder_policies');
});

it('turns plan limit errors from domain actions into an upgrade prompt', function (): void {
    $this->signInMember(WorkspaceRole::Owner);
    Route::middleware(['web', 'auth'])->get('/_payflow-test/limit', fn () => throw new PlanLimitExceeded('clients', 5));

    $this->from('/dashboard')->get('/_payflow-test/limit')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('pf_upgrade', ['limit_key' => 'clients', 'plan_suggestion' => 'starter'])
        ->assertSessionHas('error');

    $this->getJson('/_payflow-test/limit')->assertStatus(402)->assertJsonPath('limit', 'clients');
});

it('pseudonymises users referenced by financial records instead of deleting them', function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    [$finance] = $this->member(WorkspaceRole::Finance, $workspace);
    $client = Payflow::client($workspace);
    $invoice = Payflow::invoice($workspace, $owner, $client, '2026-10-01', '2026-10-30');
    app(CurrentWorkspace::class)->runAs($workspace, fn () => app(PaymentRecorder::class)->recordManual($invoice, $finance, 1000, '2026-10-21', PaymentMethod::BankTransfer, null, null, false, false));

    app(AccountDeletion::class)->delete($finance->refresh());
    $finance->refresh();

    expect(User::query()->find($finance->id))->not->toBeNull()
        ->and($finance->email)->toBe('deleted-'.$finance->id.'@deleted.invalid')
        ->and($finance->getAttribute('pseudonymised_at'))->not->toBeNull()
        ->and($finance->belongsToWorkspace($workspace))->toBeFalse();

    [$plain, $plainWorkspace] = $this->member(WorkspaceRole::Owner);
    app(AccountDeletion::class)->delete($plain);
    expect(User::query()->find($plain->id))->toBeNull();
    expect($plainWorkspace)->not->toBeNull();
});

it('exports domain tables with bank details masked', function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    $client = Payflow::client($workspace, ['name' => 'Export Client']);
    Payflow::invoice($workspace, $owner, $client, '2026-10-01', '2026-10-30');

    $sections = app(PayflowExportContributor::class)->forWorkspace($workspace);

    expect($sections)->toHaveKeys(['payflow_clients', 'payflow_invoices', 'payflow_invoice_items', 'payflow_payments', 'payflow_settings', 'payflow_payment_links', 'payflow_vendor_bank_accounts'])
        ->and($sections['payflow_clients'][0]['name'])->toBe('Export Client')
        ->and($sections['payflow_invoices'])->toHaveCount(1)
        ->and(PayflowExportContributor::mask('vendor_bank_accounts', ['iban' => 'cipher', 'last4' => '0519']))->toBe(['iban' => '***', 'last4' => '0519'])
        ->and(PayflowExportContributor::maskEmail('trusted@e2e.example'))->toBe('t•••@e2e.example')
        ->and(AuditLog::query()->where('action', 'payflow.export.masked')->where('workspace_id', $workspace->id)->exists())->toBeTrue();
});

it('runs the workspace purge hook before deleting a workspace', function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    Payflow::fakeAccount($workspace);
    $client = Payflow::client($workspace);
    Payflow::invoice($workspace, $owner, $client, '2026-10-01', '2026-10-30');

    app(WorkspaceService::class)->delete($workspace, $owner);

    expect(Invoice::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('registers no dev routes unless explicitly enabled outside production', function (): void {
    expect(Route::has('dev.payflow.reset-demo'))->toBe(config('payflow.dev_routes') === true);

    $previous = $_SERVER['APP_ENV'] ?? 'testing';
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
    $_SERVER['DEV_ROUTES'] = $_ENV['DEV_ROUTES'] = '1';
    putenv('APP_ENV=production');
    putenv('DEV_ROUTES=1');

    try {
        $config = require config_path('payflow.php');
    } finally {
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $previous;
        putenv('APP_ENV='.$previous);
        unset($_SERVER['DEV_ROUTES'], $_ENV['DEV_ROUTES']);
        putenv('DEV_ROUTES');
    }

    expect($config['dev_routes'])->toBeFalse()->and($config['allow_clock_override'])->toBeFalse();
});

it('ignores the now override in production even if configured', function (): void {
    $frozen = Payflow::freeze('2026-10-22T10:00:00Z');
    config(['payflow.allow_clock_override' => true]);
    $request = Request::create('/internal/v1/payflow/digests/run', 'POST', ['now' => '2026-12-02T12:00:00Z']);
    $middleware = new ApplyClockOverride;
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $middleware->handle($request, fn (): Response => new Response);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect(app(Clock::class))->toBe($frozen);

    $middleware->handle($request, fn (): Response => new Response);
    expect(app(Clock::class)->now()->toIso8601ZuluString())->toBe('2026-12-02T12:00:00Z');
});
