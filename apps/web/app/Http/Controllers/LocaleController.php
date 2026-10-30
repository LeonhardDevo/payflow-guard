<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Locale\Locales;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class LocaleController extends Controller
{
    public function update(Request $request, string $locale): RedirectResponse
    {
        abort_unless(Locales::isSupported($locale), 404);

        $user = $request->user();

        if ($user instanceof User && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->save();
        }

        Cookie::queue(Cookie::make(
            Locales::COOKIE,
            $locale,
            minutes: 60 * 24 * 365,
            httpOnly: false,
            sameSite: 'lax',
        ));

        return back();
    }
}
