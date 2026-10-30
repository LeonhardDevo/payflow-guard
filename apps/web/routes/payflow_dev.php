<?php

declare(strict_types=1);

use App\Http\Controllers\Dev\Payflow\PayflowDevController;
use Illuminate\Support\Facades\Route;

if (config('payflow.dev_routes') === true && ! app()->isProduction()) {
    Route::middleware('throttle:60,1')->prefix('dev/payflow')->name('dev.payflow.')->group(function (): void {
        Route::post('reset-demo', [PayflowDevController::class, 'resetDemo'])->name('reset-demo');
        Route::get('totp', [PayflowDevController::class, 'totp'])->name('totp');
        Route::get('payment-endpoint', [PayflowDevController::class, 'paymentEndpoint'])->name('payment-endpoint');
        Route::post('fake-pay/{token}', [PayflowDevController::class, 'fakePay'])->where('token', '[A-Za-z0-9]{20,48}')->name('fake-pay');
    });
}
