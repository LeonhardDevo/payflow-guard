<?php

declare(strict_types=1);

use App\Http\Controllers\Payflow\PublicInvoiceController;
use App\Http\Controllers\Payflow\RecipientChoicesController;
use App\Http\Controllers\Payflow\TrustedController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:payflow-public')->group(function (): void {
    Route::get('i/{token}', [PublicInvoiceController::class, 'show'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.invoices.show');
    Route::get('i/{token}/pdf', [PublicInvoiceController::class, 'pdf'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.invoices.pdf');
    Route::post('i/{token}/pay', [PublicInvoiceController::class, 'pay'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.invoices.pay');
    Route::get('i/{token}/thanks', [PublicInvoiceController::class, 'thanks'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.invoices.thanks');
    Route::get('i/{token}/status', [PublicInvoiceController::class, 'status'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.invoices.status');
});

Route::middleware('throttle:payflow-choices')->group(function (): void {
    Route::get('i/{token}/reminders', [RecipientChoicesController::class, 'show'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.invoices.reminders');
    Route::post('i/{token}/reminders/paid', [RecipientChoicesController::class, 'paid'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.invoices.reminders.paid');
    Route::post('i/{token}/reminders/stop', [RecipientChoicesController::class, 'stop'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.invoices.reminders.stop');
    Route::post('i/{token}/reminders/one-click', [RecipientChoicesController::class, 'oneClick'])->where('token', '[A-Za-z0-9]{20,48}')->name('public.reminders.one-click');
});

Route::middleware('throttle:payflow-public')->group(function (): void {
    Route::get('tc/verify/{token}', [TrustedController::class, 'verify'])->whereNumber('token')->middleware('signed')->name('trusted.verify');
    Route::get('tc/verify/{token}/decline', [TrustedController::class, 'declinePrompt'])->whereNumber('token')->middleware('signed')->name('trusted.verify.decline');
    Route::post('tc/verify/{token}/decline', [TrustedController::class, 'decline'])->whereNumber('token')->middleware('signed')->name('trusted.verify.decline.store');
    Route::get('tc/{token}', [TrustedController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->name('trusted.show');
});

Route::post('tc/{token}', [TrustedController::class, 'decide'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:payflow-trusted')->name('trusted.decide');
