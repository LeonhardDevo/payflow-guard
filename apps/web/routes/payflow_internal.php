<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\Payflow\AiDataController;
use App\Http\Controllers\Internal\Payflow\AiGuardDataController;
use App\Http\Controllers\Internal\Payflow\BotController;
use App\Http\Controllers\Internal\Payflow\DigestsController;
use App\Http\Controllers\Internal\Payflow\ImportsController;
use App\Http\Controllers\Internal\Payflow\InvoicePdfController;
use App\Http\Controllers\Internal\Payflow\MaintenanceController;
use App\Http\Controllers\Internal\Payflow\PaymentLinksController;
use App\Http\Controllers\Internal\Payflow\PayoutEscalationsController;
use App\Http\Controllers\Internal\Payflow\RemindersController;
use App\Http\Controllers\Internal\Payflow\WorkspacesController;
use Illuminate\Support\Facades\Route;

Route::prefix('payflow')->name('payflow.')->middleware('payflow.clock')->group(function (): void {
    Route::middleware('internal.caller:worker')->group(function (): void {
        Route::get('workspaces', [WorkspacesController::class, 'index'])->name('workspaces.index');
        Route::post('workspaces/{workspace}/reminders/plan', [RemindersController::class, 'plan'])->whereNumber('workspace')->name('reminders.plan');
        Route::post('reminders/{reminder}/dispatch', [RemindersController::class, 'dispatch'])->whereNumber('reminder')->name('reminders.dispatch');
        Route::post('workspaces/{workspace}/risk/recompute', [WorkspacesController::class, 'recomputeRisk'])->whereNumber('workspace')->name('risk.recompute');
        Route::get('payment-links/sync-candidates', [PaymentLinksController::class, 'candidates'])->name('payment-links.candidates');
        Route::post('payment-links/{link}/sync', [PaymentLinksController::class, 'sync'])->whereNumber('link')->name('payment-links.sync');
        Route::post('imports/{import}/process-batch', [ImportsController::class, 'processBatch'])->whereNumber('import')->name('imports.process-batch');
        Route::get('invoices/{invoice}/pdf', InvoicePdfController::class)->whereNumber('invoice')->name('invoices.pdf');
        Route::post('maintenance/purge', MaintenanceController::class)->name('maintenance.purge');
        Route::post('digests/run', DigestsController::class)->name('digests.run');
        Route::post('payouts/escalations/run', PayoutEscalationsController::class)->name('payouts.escalations');
        Route::get('bot/views/{view}', [BotController::class, 'view'])->where('view', '[a-z]+')->name('bot.views');
        Route::post('bot/payouts/{payout}/decision', [BotController::class, 'payoutDecision'])->whereNumber('payout')->name('bot.payouts.decision');
        Route::post('bot/invoices/remind', [BotController::class, 'remind'])->name('bot.invoices.remind');
        Route::post('bot/alerts/{alert}/acknowledge', [BotController::class, 'acknowledge'])->whereNumber('alert')->name('bot.alerts.acknowledge');
    });

    Route::middleware('internal.caller:ai')->prefix('ai')->name('ai.')->group(function (): void {
        Route::get('invoices/overdue', [AiDataController::class, 'overdue'])->name('invoices.overdue');
        Route::get('invoices/lookup', [AiDataController::class, 'lookup'])->name('invoices.lookup');
        Route::get('clients/profile', [AiDataController::class, 'clientProfile'])->name('clients.profile');
        Route::get('reminders/next', [AiDataController::class, 'nextReminder'])->name('reminders.next');
        Route::get('clients/terms', [AiDataController::class, 'clientTerms'])->name('clients.terms');
        Route::get('ar/summary', [AiDataController::class, 'arSummary'])->name('ar.summary');
        Route::get('clients/late-payers', [AiDataController::class, 'latePayers'])->name('clients.late-payers');
        Route::get('vendors/bank-changes', [AiGuardDataController::class, 'bankChanges'])->name('vendors.bank-changes');
        Route::get('payouts/pending', [AiGuardDataController::class, 'pendingPayouts'])->name('payouts.pending');
    });
});
