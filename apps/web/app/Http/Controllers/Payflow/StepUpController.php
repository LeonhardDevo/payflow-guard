<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Audit\Auditor;
use App\Domain\Guard\Enums\StepUpMethod;
use App\Domain\Guard\StepUp\StepUpGate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticationProvider;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

final class StepUpController extends PayflowController
{
    public function __invoke(Request $request, Auditor $auditor): Response|RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'method' => ['required', Rule::enum(StepUpMethod::class)->except([StepUpMethod::None])],
            'assertion' => ['nullable', 'array'],
            'code' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
        $method = StepUpMethod::from((string) $data['method']);
        $allowed = StepUpGate::methods($user, $this->workspace());

        $ok = in_array($method->value, $allowed, true) && match ($method) {
            StepUpMethod::Passkey => $this->passkey($request, $user, is_array($data['assertion'] ?? null) ? $data['assertion'] : []),
            StepUpMethod::Totp => $this->totp($user, (string) ($data['code'] ?? '')),
            StepUpMethod::Password => is_string($data['password'] ?? null) && Hash::check($data['password'], $user->password),
            StepUpMethod::None => false,
        };

        $auditor->record(
            $ok ? 'payflow.stepup.succeeded' : 'payflow.stepup.failed',
            $user,
            metadata: ['method' => $method->value],
            actor: $user,
            subjectLabel: $user->name,
        );

        if (! $ok) {
            throw ValidationException::withMessages([
                $method === StepUpMethod::Password ? 'password' : ($method === StepUpMethod::Totp ? 'code' : 'assertion') => (string) __('payflow.errors.step_up_failed'),
            ]);
        }

        StepUpGate::record($request, $method);

        if ($request->header('X-Inertia') !== null) {
            return redirect()->back(303);
        }

        return response()->noContent();
    }

    private function totp(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at === null || $code === '') {
            return false;
        }

        try {
            $secret = (string) Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        } catch (Throwable) {
            return false;
        }

        return app(TwoFactorAuthenticationProvider::class)->verify($secret, preg_replace('/\s+/', '', $code) ?? '');
    }

    /** @param  array<mixed>  $assertion */
    private function passkey(Request $request, User $user, array $assertion): bool
    {
        $options = $request->session()->pull('passkey.verification_options');

        if (! is_string($options) || $assertion === []) {
            return false;
        }

        try {
            $credential = WebAuthn::fromJson((string) json_encode($assertion), PublicKeyCredential::class);
            $requestOptions = WebAuthn::fromJson($options, PublicKeyCredentialRequestOptions::class);
            app(VerifyPasskey::class)($credential, $requestOptions, $user);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
