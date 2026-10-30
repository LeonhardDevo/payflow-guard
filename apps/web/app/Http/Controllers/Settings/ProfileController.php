<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Privacy\DeletionRequests;
use App\Privacy\PrivacyException;
use App\Support\Flash;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Flash::success('notifications.profile.updated');

        return to_route('profile.edit');
    }

    public function destroy(ProfileDeleteRequest $request, DeletionRequests $deletions): RedirectResponse
    {
        try {
            $deletion = $deletions->scheduleUser($request->user());
        } catch (PrivacyException $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        Flash::success('notifications.privacy.deletion_scheduled', [
            'date' => $deletion->scheduled_for->settings(['locale' => App::getLocale()])->isoFormat('LL'),
        ]);

        return to_route('settings.privacy');
    }
}
