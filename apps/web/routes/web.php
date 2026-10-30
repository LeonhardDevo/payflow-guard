<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AiAdminController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\FakeCheckoutController;
use App\Http\Controllers\EmailOptOutController;
use App\Http\Controllers\InvitationAcceptController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\WellKnownController;
use App\Http\Controllers\WorkspaceSwitchController;
use App\Locale\Locales;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketingController::class, 'home'])->name('home');
Route::get('pricing', [MarketingController::class, 'pricing'])->name('pricing');
Route::get('security', [MarketingController::class, 'security'])->name('security');

Route::get('legal', [LegalController::class, 'index'])->name('legal.index');
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('legal/accept', [LegalController::class, 'pending'])->name('legal.accept');
    Route::post('legal/accept', [LegalController::class, 'accept'])->name('legal.accept.store');
});
Route::get('legal/{doc}', [LegalController::class, 'show'])
    ->where('doc', '[a-z0-9][a-z0-9-]{0,63}')
    ->name('legal');

Route::get('.well-known/security.txt', [WellKnownController::class, 'securityTxt'])->name('well-known.security');
Route::get('robots.txt', [WellKnownController::class, 'robots'])->name('robots');

Route::get('email/opt-out/{token}', [EmailOptOutController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+')
    ->middleware('throttle:opt-out')
    ->name('email.opt-out');
Route::post('email/opt-out/{token}', [EmailOptOutController::class, 'store'])
    ->where('token', '[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+')
    ->middleware('throttle:opt-out')
    ->name('email.opt-out.store');

Route::post('locale/{locale}', [LocaleController::class, 'update'])
    ->whereIn('locale', Locales::supported())
    ->middleware('throttle:locale')
    ->name('locale.update');

Route::get('invitations/{token}', [InvitationAcceptController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('invitations.show');
Route::post('invitations/{token}', [InvitationAcceptController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:invitations')
    ->name('invitations.accept');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('onboarding/workspace', [OnboardingController::class, 'create'])->name('onboarding.workspace');
    Route::post('onboarding/workspace', [OnboardingController::class, 'store'])->name('onboarding.workspace.store');

    Route::post('workspaces/{workspace}/switch', WorkspaceSwitchController::class)->name('workspaces.switch');

    Route::post('notifications/read', NotificationsController::class)
        ->middleware('throttle:60,1')
        ->name('notifications.read');

    Route::middleware('workspace')->group(function (): void {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('assistant', [AssistantController::class, 'index'])->name('assistant');
        Route::post('assistant/ask', [AssistantController::class, 'ask'])
            ->middleware(['throttle:assistant', 'plan.limit:ai_queries_monthly'])
            ->name('assistant.ask');
        Route::post('assistant/actions', [AssistantController::class, 'action'])
            ->middleware('throttle:assistant')
            ->name('assistant.actions');
        Route::get('assistant/history', [AssistantController::class, 'history'])->name('assistant.history');

        Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
        Route::post('knowledge', [KnowledgeController::class, 'store'])
            ->middleware(['throttle:uploads', 'plan.limit:documents'])
            ->name('knowledge.store');
        Route::get('knowledge/{document}', [KnowledgeController::class, 'show'])->name('knowledge.show');
        Route::patch('knowledge/{document}', [KnowledgeController::class, 'update'])->name('knowledge.update');
        Route::delete('knowledge/{document}', [KnowledgeController::class, 'destroy'])->name('knowledge.destroy');

        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
    });

    Route::middleware('can:admin.ai')->prefix('admin/ai')->group(function (): void {
        Route::get('/', [AiAdminController::class, 'index'])->name('admin.ai');
        Route::put('settings', [AiAdminController::class, 'updateSettings'])->name('admin.ai.settings');
        Route::put('keys/{provider}', [AiAdminController::class, 'updateKey'])->name('admin.ai.keys');
        Route::get('providers/{provider}/models', [AiAdminController::class, 'models'])->name('admin.ai.models');
        Route::post('test', [AiAdminController::class, 'test'])->middleware('throttle:10,1')->name('admin.ai.test');
    });

    Route::middleware('fake.billing')->group(function (): void {
        Route::get('dev/fake-checkout/{session}', [FakeCheckoutController::class, 'show'])
            ->where('session', 'cs_test_[A-Za-z0-9]+')
            ->name('dev.fake-checkout');
        Route::post('dev/fake-checkout/{session}', [FakeCheckoutController::class, 'pay'])
            ->where('session', 'cs_test_[A-Za-z0-9]+')
            ->middleware('throttle:billing')
            ->name('dev.fake-checkout.pay');
    });
});

Route::get('knowledge/{document}/download', [KnowledgeController::class, 'download'])
    ->middleware(['auth', 'verified', 'signed', 'workspace'])
    ->name('knowledge.download');

require __DIR__.'/settings.php';
require __DIR__.'/payflow.php';
require __DIR__.'/payflow_public.php';
require __DIR__.'/payflow_dev.php';
