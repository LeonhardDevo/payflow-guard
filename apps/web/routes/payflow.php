<?php

declare(strict_types=1);

use App\Http\Controllers\Payflow\AnalyticsController;
use App\Http\Controllers\Payflow\BankChangesController;
use App\Http\Controllers\Payflow\ClientsController;
use App\Http\Controllers\Payflow\CollectionsSettingsController;
use App\Http\Controllers\Payflow\DashboardController;
use App\Http\Controllers\Payflow\GuardController;
use App\Http\Controllers\Payflow\GuardSettingsController;
use App\Http\Controllers\Payflow\ImportsController;
use App\Http\Controllers\Payflow\InvoiceActionsController;
use App\Http\Controllers\Payflow\InvoicePaymentsController;
use App\Http\Controllers\Payflow\InvoiceRemindersController;
use App\Http\Controllers\Payflow\InvoicesController;
use App\Http\Controllers\Payflow\NotificationSettingsController;
use App\Http\Controllers\Payflow\PaymentSettingsController;
use App\Http\Controllers\Payflow\PayoutsController;
use App\Http\Controllers\Payflow\ReceivingDetailsController;
use App\Http\Controllers\Payflow\RemindersController;
use App\Http\Controllers\Payflow\SearchController;
use App\Http\Controllers\Payflow\StepUpController;
use App\Http\Controllers\Payflow\VendorsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'workspace'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post('pf/onboarding/dismiss', [DashboardController::class, 'dismissOnboarding'])->name('pf.onboarding.dismiss');
    Route::get('pf/search', SearchController::class)->middleware('throttle:120,1')->name('pf.search');

    Route::get('invoices', [InvoicesController::class, 'index'])->middleware('can:invoices.view')->name('invoices.index');
    Route::get('invoices/export', [InvoicesController::class, 'export'])->middleware('can:invoices.view')->name('invoices.export');
    Route::get('invoices/create', [InvoicesController::class, 'create'])->middleware(['can:invoices.manage', 'plan.limit:invoices_per_month'])->name('invoices.create');
    Route::post('invoices', [InvoicesController::class, 'store'])->middleware(['can:invoices.manage', 'plan.limit:invoices_per_month'])->name('invoices.store');
    Route::post('invoices/bulk', [InvoiceActionsController::class, 'bulk'])->middleware('can:invoices.view')->name('invoices.bulk');
    Route::get('invoices/{invoice}', [InvoicesController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
    Route::get('invoices/{invoice}/edit', [InvoicesController::class, 'edit'])->whereNumber('invoice')->name('invoices.edit');
    Route::put('invoices/{invoice}', [InvoicesController::class, 'update'])->whereNumber('invoice')->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoicesController::class, 'destroy'])->whereNumber('invoice')->name('invoices.destroy');
    Route::get('invoices/{invoice}/pdf', [InvoicesController::class, 'pdf'])->whereNumber('invoice')->name('invoices.pdf');

    Route::post('invoices/{invoice}/send', [InvoiceActionsController::class, 'send'])->whereNumber('invoice')->name('invoices.send');
    Route::post('invoices/{invoice}/mark-sent', [InvoiceActionsController::class, 'markSent'])->whereNumber('invoice')->name('invoices.mark-sent');
    Route::post('invoices/{invoice}/payment-link', [InvoiceActionsController::class, 'paymentLink'])->whereNumber('invoice')->name('invoices.payment-link');
    Route::post('invoices/{invoice}/pause', [InvoiceActionsController::class, 'pause'])->whereNumber('invoice')->name('invoices.pause');
    Route::post('invoices/{invoice}/dispute', [InvoiceActionsController::class, 'dispute'])->whereNumber('invoice')->name('invoices.dispute');
    Route::post('invoices/{invoice}/resolve-dispute', [InvoiceActionsController::class, 'resolveDispute'])->whereNumber('invoice')->name('invoices.resolve-dispute');
    Route::post('invoices/{invoice}/void', [InvoiceActionsController::class, 'void'])->whereNumber('invoice')->name('invoices.void');
    Route::post('invoices/{invoice}/write-off', [InvoiceActionsController::class, 'writeOff'])->whereNumber('invoice')->name('invoices.write-off');
    Route::post('invoices/{invoice}/late-fee', [InvoiceActionsController::class, 'lateFee'])->whereNumber('invoice')->name('invoices.late-fee');
    Route::post('invoices/{invoice}/confirm-first-contact', [InvoiceActionsController::class, 'confirmFirstContact'])->whereNumber('invoice')->name('invoices.confirm-first-contact');
    Route::post('invoices/{invoice}/acknowledge-reported-payment', [InvoiceActionsController::class, 'acknowledgeReportedPayment'])->whereNumber('invoice')->name('invoices.acknowledge-reported-payment');

    Route::post('invoices/{invoice}/payments', [InvoicePaymentsController::class, 'store'])->whereNumber('invoice')->name('invoices.payments.store');
    Route::post('payments/{payment}/reverse', [InvoicePaymentsController::class, 'reverse'])->whereNumber('payment')->name('payments.reverse');

    Route::post('invoices/{invoice}/reminders/preview', [InvoiceRemindersController::class, 'preview'])->whereNumber('invoice')->name('invoices.reminders.preview');
    Route::post('invoices/{invoice}/reminders', [InvoiceRemindersController::class, 'store'])->whereNumber('invoice')->middleware('throttle:30,1')->name('invoices.reminders.store');
    Route::post('invoices/{invoice}/reminders/ai-draft', [InvoiceRemindersController::class, 'aiDraft'])->whereNumber('invoice')->middleware('throttle:20,1')->name('invoices.reminders.ai-draft');

    Route::get('clients', [ClientsController::class, 'index'])->middleware('can:clients.view')->name('clients.index');
    Route::get('clients/options', [ClientsController::class, 'options'])->middleware('can:clients.view')->name('clients.options');
    Route::get('clients/create', [ClientsController::class, 'create'])->middleware(['can:clients.manage', 'plan.limit:clients'])->name('clients.create');
    Route::post('clients', [ClientsController::class, 'store'])->middleware(['can:clients.manage', 'plan.limit:clients'])->name('clients.store');
    Route::get('clients/{client}', [ClientsController::class, 'show'])->whereNumber('client')->name('clients.show');
    Route::get('clients/{client}/edit', [ClientsController::class, 'edit'])->whereNumber('client')->name('clients.edit');
    Route::put('clients/{client}', [ClientsController::class, 'update'])->whereNumber('client')->name('clients.update');
    Route::delete('clients/{client}', [ClientsController::class, 'destroy'])->whereNumber('client')->name('clients.destroy');
    Route::post('clients/{client}/pause', [ClientsController::class, 'pause'])->whereNumber('client')->name('clients.pause');
    Route::post('clients/{client}/anonymise', [ClientsController::class, 'anonymise'])->whereNumber('client')->middleware('guard.stepup')->name('clients.anonymise');

    Route::get('reminders', [RemindersController::class, 'index'])->middleware('can:invoices.view')->name('reminders.index');
    Route::get('reminders/{reminder}', [RemindersController::class, 'show'])->whereNumber('reminder')->name('reminders.show');
    Route::post('reminders/{reminder}/send-now', [RemindersController::class, 'sendNow'])->whereNumber('reminder')->name('reminders.send-now');
    Route::post('reminders/{reminder}/cancel', [RemindersController::class, 'cancel'])->whereNumber('reminder')->name('reminders.cancel');
    Route::post('reminders/{reminder}/retry', [RemindersController::class, 'retry'])->whereNumber('reminder')->name('reminders.retry');

    Route::get('analytics', AnalyticsController::class)->middleware('can:analytics.view')->name('analytics.index');

    Route::get('imports', [ImportsController::class, 'index'])->middleware('can:imports.run')->name('imports.index');
    Route::post('imports', [ImportsController::class, 'store'])->middleware(['can:imports.run', 'plan.limit:imports_per_month', 'throttle:uploads'])->name('imports.store');
    Route::get('imports/template/{kind}', [ImportsController::class, 'template'])->whereIn('kind', ['clients', 'invoices'])->middleware('can:imports.run')->name('imports.template');
    Route::get('imports/{import}', [ImportsController::class, 'show'])->whereNumber('import')->name('imports.show');
    Route::post('imports/{import}/start', [ImportsController::class, 'start'])->whereNumber('import')->name('imports.start');
    Route::get('imports/{import}/activation', [ImportsController::class, 'activation'])->whereNumber('import')->name('imports.activation');
    Route::post('imports/{import}/activate', [ImportsController::class, 'activate'])->whereNumber('import')->name('imports.activate');
    Route::post('imports/{import}/dismiss', [ImportsController::class, 'dismiss'])->whereNumber('import')->name('imports.dismiss');

    Route::middleware('can:reminders.configure')->group(function (): void {
        Route::get('settings/collections', [CollectionsSettingsController::class, 'show'])->name('settings.collections');
        Route::put('settings/collections', [CollectionsSettingsController::class, 'update'])->name('settings.collections.update');
        Route::post('settings/collections/policies', [CollectionsSettingsController::class, 'storePolicy'])->middleware('plan.limit:reminder_policies')->name('settings.policies.store');
        Route::put('settings/collections/policies/{policy}', [CollectionsSettingsController::class, 'updatePolicy'])->whereNumber('policy')->name('settings.policies.update');
        Route::delete('settings/collections/policies/{policy}', [CollectionsSettingsController::class, 'destroyPolicy'])->whereNumber('policy')->name('settings.policies.destroy');
        Route::put('settings/collections/templates/{template}', [CollectionsSettingsController::class, 'updateTemplate'])->whereNumber('template')->name('settings.templates.update');
        Route::post('settings/collections/templates/{template}/reset', [CollectionsSettingsController::class, 'resetTemplate'])->whereNumber('template')->name('settings.templates.reset');
        Route::post('settings/collections/templates/preview', [CollectionsSettingsController::class, 'previewTemplate'])->name('settings.templates.preview');
    });

    Route::middleware('can:payments.configure')->group(function (): void {
        Route::get('settings/payments', [PaymentSettingsController::class, 'show'])->name('settings.payments');
        Route::post('settings/payments/stripe', [PaymentSettingsController::class, 'stripe'])->middleware(['guard.stepup', 'throttle:billing'])->name('settings.payments.stripe');
        Route::post('settings/payments/stripe/verify', [PaymentSettingsController::class, 'verify'])->middleware('throttle:6,1')->name('settings.payments.stripe.verify');
        Route::post('settings/payments/yookassa', [PaymentSettingsController::class, 'yookassa'])->middleware(['guard.stepup', 'throttle:billing'])->name('settings.payments.yookassa');
        Route::post('settings/payments/demo', [PaymentSettingsController::class, 'demo'])->name('settings.payments.demo');
        Route::delete('settings/payments', [PaymentSettingsController::class, 'disconnect'])->middleware('guard.stepup')->name('settings.payments.disconnect');
    });

    Route::post('guard/step-up', StepUpController::class)->middleware('throttle:5,1')->name('guard.step-up');
    Route::get('guard', [GuardController::class, 'index'])->name('guard.index');
    Route::post('guard/alerts/{alert}/acknowledge', [GuardController::class, 'acknowledge'])->whereNumber('alert')->name('guard.alerts.acknowledge');
    Route::post('guard/alerts/{alert}/resolve', [GuardController::class, 'resolve'])->whereNumber('alert')->name('guard.alerts.resolve');
    Route::get('guard/bank-changes/{account}', [BankChangesController::class, 'show'])->whereNumber('account')->name('guard.bank-changes.show');
    Route::post('guard/bank-changes/{account}/verify', [BankChangesController::class, 'verify'])->whereNumber('account')->middleware('throttle:20,1')->name('guard.bank-changes.verify');
    Route::post('guard/bank-changes/{account}/reject', [BankChangesController::class, 'reject'])->whereNumber('account')->name('guard.bank-changes.reject');
    Route::post('guard/bank-changes/{account}/solo-confirmation', [BankChangesController::class, 'soloConfirmation'])->whereNumber('account')->middleware('throttle:6,1')->name('guard.bank-changes.solo-confirmation');

    Route::get('vendors', [VendorsController::class, 'index'])->middleware('can:vendors.view')->name('vendors.index');
    Route::get('vendors/export/bank-details', [VendorsController::class, 'exportUnmasked'])->middleware(['guard.stepup', 'throttle:6,1'])->name('vendors.export.unmasked');
    Route::post('vendors/notice/dismiss', [VendorsController::class, 'dismissNotice'])->middleware('can:vendors.view')->name('vendors.notice.dismiss');
    Route::get('vendors/create', [VendorsController::class, 'create'])->middleware(['can:vendors.manage', 'plan.limit:vendors'])->name('vendors.create');
    Route::post('vendors', [VendorsController::class, 'store'])->middleware(['can:vendors.manage', 'plan.limit:vendors'])->name('vendors.store');
    Route::get('vendors/{vendor}', [VendorsController::class, 'show'])->whereNumber('vendor')->name('vendors.show');
    Route::get('vendors/{vendor}/edit', [VendorsController::class, 'edit'])->whereNumber('vendor')->name('vendors.edit');
    Route::put('vendors/{vendor}', [VendorsController::class, 'update'])->whereNumber('vendor')->name('vendors.update');
    Route::post('vendors/{vendor}/hold', [VendorsController::class, 'hold'])->whereNumber('vendor')->name('vendors.hold');
    Route::post('vendors/{vendor}/release-hold', [VendorsController::class, 'releaseHold'])->whereNumber('vendor')->name('vendors.release-hold');
    Route::delete('vendors/{vendor}', [VendorsController::class, 'destroy'])->whereNumber('vendor')->name('vendors.destroy');
    Route::post('vendors/{vendor}/anonymise', [VendorsController::class, 'anonymise'])->whereNumber('vendor')->middleware('guard.stepup')->name('vendors.anonymise');
    Route::get('vendors/{vendor}/bank-changes/create', [VendorsController::class, 'createBankChange'])->whereNumber('vendor')->name('vendors.bank-changes.create');
    Route::post('vendors/{vendor}/bank-changes', [VendorsController::class, 'storeBankChange'])->whereNumber('vendor')->middleware('throttle:20,1')->name('vendors.bank-changes.store');
    Route::post('vendors/{vendor}/bank-accounts/{account}/reveal', [VendorsController::class, 'reveal'])->whereNumber(['vendor', 'account'])->middleware(['guard.stepup', 'throttle:20,1'])->name('vendors.bank-accounts.reveal');

    Route::get('payouts', [PayoutsController::class, 'index'])->middleware('can:payouts.view')->name('payouts.index');
    Route::get('payouts/create', [PayoutsController::class, 'create'])->middleware('can:payouts.create')->name('payouts.create');
    Route::post('payouts', [PayoutsController::class, 'store'])->middleware('can:payouts.create')->name('payouts.store');
    Route::get('payouts/{payout}', [PayoutsController::class, 'show'])->whereNumber('payout')->name('payouts.show');
    Route::put('payouts/{payout}', [PayoutsController::class, 'update'])->whereNumber('payout')->name('payouts.update');
    Route::post('payouts/{payout}/submit', [PayoutsController::class, 'submit'])->whereNumber('payout')->name('payouts.submit');
    Route::post('payouts/{payout}/resubmit', [PayoutsController::class, 'resubmit'])->whereNumber('payout')->name('payouts.resubmit');
    Route::post('payouts/{payout}/approve', [PayoutsController::class, 'approve'])->whereNumber('payout')->middleware('throttle:30,1')->name('payouts.approve');
    Route::post('payouts/{payout}/reject', [PayoutsController::class, 'reject'])->whereNumber('payout')->middleware('throttle:30,1')->name('payouts.reject');
    Route::post('payouts/{payout}/release', [PayoutsController::class, 'release'])->whereNumber('payout')->middleware('throttle:30,1')->name('payouts.release');
    Route::post('payouts/{payout}/cancel', [PayoutsController::class, 'cancel'])->whereNumber('payout')->name('payouts.cancel');
    Route::post('payouts/{payout}/trusted-confirmation/resend', [PayoutsController::class, 'resendConfirmation'])->whereNumber('payout')->middleware('throttle:6,1')->name('payouts.trusted-confirmation.resend');

    Route::get('settings/guard', [GuardSettingsController::class, 'show'])->middleware('can:payouts.view')->name('settings.guard');
    Route::middleware('can:guard.configure')->group(function (): void {
        Route::put('settings/guard', [GuardSettingsController::class, 'update'])->middleware('guard.stepup')->name('settings.guard.update');
        Route::post('settings/guard/trusted-contact', [GuardSettingsController::class, 'storeTrustedContact'])->middleware(['guard.stepup', 'throttle:6,1'])->name('settings.guard.trusted-contact.store');
        Route::delete('settings/guard/trusted-contact', [GuardSettingsController::class, 'destroyTrustedContact'])->middleware('guard.stepup')->name('settings.guard.trusted-contact.destroy');
        Route::post('settings/collections/receiving-details', [ReceivingDetailsController::class, 'store'])->middleware('guard.stepup')->name('settings.receiving.request');
        Route::post('settings/collections/receiving-details/{version}/cancel', [ReceivingDetailsController::class, 'cancel'])->whereNumber('version')->name('settings.receiving.cancel');
    });
    Route::post('settings/collections/receiving-details/{version}/confirm', [ReceivingDetailsController::class, 'confirm'])->whereNumber('version')->name('settings.receiving.confirm');
    Route::post('settings/collections/receiving-details/{version}/reject', [ReceivingDetailsController::class, 'reject'])->whereNumber('version')->name('settings.receiving.reject');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'show'])->name('settings.notifications');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');
});
