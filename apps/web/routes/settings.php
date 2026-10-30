<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\IntegrationsController;
use App\Http\Controllers\Settings\InvitationsController;
use App\Http\Controllers\Settings\MembersController;
use App\Http\Controllers\Settings\PrivacyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\WorkspaceSettingsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->middleware('throttle:6,1')->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::prefix('settings/privacy')->name('settings.privacy')->group(function (): void {
        Route::get('/', [PrivacyController::class, 'show'])->name('');
        Route::post('export', [PrivacyController::class, 'export'])
            ->middleware('throttle:privacy')
            ->name('.export');
        Route::post('delete-account', [PrivacyController::class, 'scheduleDeletion'])
            ->middleware('throttle:6,1')
            ->name('.delete-account');
        Route::delete('delete-account', [PrivacyController::class, 'cancelDeletion'])->name('.delete-account.cancel');
        Route::patch('workspace', [PrivacyController::class, 'updateWorkspace'])
            ->middleware('workspace')
            ->name('.workspace');
    });

    Route::get('settings/privacy/exports/{export}/download', [PrivacyController::class, 'download'])
        ->middleware('signed')
        ->whereNumber('export')
        ->name('settings.privacy.exports.download');

    Route::middleware('workspace')->prefix('settings')->name('settings.')->group(function (): void {
        Route::get('workspace', [WorkspaceSettingsController::class, 'edit'])->name('workspace');
        Route::patch('workspace', [WorkspaceSettingsController::class, 'update'])->name('workspace.update');
        Route::delete('workspace', [WorkspaceSettingsController::class, 'destroy'])
            ->middleware('throttle:6,1')
            ->name('workspace.destroy');

        Route::post('workspace/export', [PrivacyController::class, 'exportWorkspace'])
            ->middleware('throttle:privacy')
            ->name('workspace.export');
        Route::post('workspace/delete', [PrivacyController::class, 'scheduleWorkspaceDeletion'])
            ->middleware('throttle:6,1')
            ->name('workspace.delete');
        Route::delete('workspace/delete', [PrivacyController::class, 'cancelWorkspaceDeletion'])->name('workspace.delete.cancel');

        Route::get('members', [MembersController::class, 'index'])->name('members');
        Route::post('members/invitations', [InvitationsController::class, 'store'])
            ->middleware(['throttle:invitations', 'plan.limit:seats'])
            ->name('members.invitations.store');
        Route::delete('members/invitations/{invitation}', [InvitationsController::class, 'destroy'])
            ->name('members.invitations.destroy');
        Route::patch('members/{user}', [MembersController::class, 'update'])->name('members.update');
        Route::delete('members/{user}', [MembersController::class, 'destroy'])->name('members.destroy');

        Route::get('billing', [BillingController::class, 'show'])->name('billing');
        Route::post('billing/checkout', [BillingController::class, 'checkout'])
            ->middleware('throttle:billing')
            ->name('billing.checkout');
        Route::post('billing/portal', [BillingController::class, 'portal'])
            ->middleware('throttle:billing')
            ->name('billing.portal');
        Route::post('billing/change-plan', [BillingController::class, 'changePlan'])
            ->middleware('throttle:billing')
            ->name('billing.change-plan');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])
            ->middleware('throttle:billing')
            ->name('billing.cancel');
        Route::post('billing/resume', [BillingController::class, 'resume'])
            ->middleware('throttle:billing')
            ->name('billing.resume');

        Route::get('integrations', [IntegrationsController::class, 'show'])->name('integrations');
        Route::post('integrations/telegram/link', [IntegrationsController::class, 'link'])
            ->middleware('throttle:telegram-link')
            ->name('integrations.telegram.link');
        Route::delete('integrations/telegram', [IntegrationsController::class, 'unlink'])
            ->name('integrations.telegram.unlink');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
