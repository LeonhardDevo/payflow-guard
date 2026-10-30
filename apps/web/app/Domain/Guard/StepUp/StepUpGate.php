<?php

declare(strict_types=1);

namespace App\Domain\Guard\StepUp;

use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\StepUpMethod;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

final class StepUpGate
{
    public const string SESSION_AT = 'payflow.stepup_at';

    public const string SESSION_METHOD = 'payflow.stepup_method';

    public static function window(): int
    {
        return (int) config('payflow.stepup_window_seconds', 900);
    }

    public static function freshUntilInstant(Request $request): ?CarbonImmutable
    {
        $session = self::session($request);

        if ($session === null) {
            return null;
        }

        $at = $session->get(self::SESSION_AT);

        if (! is_int($at) && ! (is_string($at) && ctype_digit($at))) {
            return null;
        }

        $until = CarbonImmutable::createFromTimestampUTC((int) $at)->addSeconds(self::window());

        return $until->greaterThan(app(Clock::class)->now()) ? $until : null;
    }

    public static function isFresh(Request $request): bool
    {
        return self::freshUntilInstant($request) !== null;
    }

    public static function freshUntil(Request $request): ?string
    {
        return self::freshUntilInstant($request)?->format('Y-m-d\TH:i:s\Z');
    }

    public static function method(Request $request): ?StepUpMethod
    {
        if (! self::isFresh($request)) {
            return null;
        }

        $method = self::session($request)?->get(self::SESSION_METHOD);

        return is_string($method) ? StepUpMethod::tryFrom($method) : null;
    }

    private static function session(Request $request): ?Session
    {
        if ($request->hasSession()) {
            return $request->session();
        }

        $store = app()->bound('session.store') ? app('session.store') : null;

        return $store instanceof Session ? $store : null;
    }

    public static function record(Request $request, StepUpMethod $method): void
    {
        $request->session()->put(self::SESSION_AT, app(Clock::class)->now()->getTimestamp());
        $request->session()->put(self::SESSION_METHOD, $method->value);
    }

    /** @return list<string> */
    public static function methodsFor(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        return self::methods($user, app(CurrentWorkspace::class)->get());
    }

    /** @return list<string> */
    public static function methods(User $user, ?Workspace $workspace): array
    {
        $methods = [];

        if ($user->passkeys()->exists()) {
            $methods[] = StepUpMethod::Passkey->value;
        }

        if ($user->two_factor_confirmed_at !== null) {
            $methods[] = StepUpMethod::Totp->value;
        }

        $requireSecondFactor = $workspace !== null && WorkspaceProvisioner::settingsFor($workspace)->require_second_factor;

        if ($methods === [] && ! $requireSecondFactor) {
            $methods[] = StepUpMethod::Password->value;
        }

        return $methods;
    }

    public static function require(Request $request): StepUpMethod
    {
        self::assertSecondFactor($request);

        $method = self::method($request);

        if ($method === null) {
            throw StepUpRequired::stepUp($request);
        }

        return $method;
    }

    public static function assertSecondFactor(Request $request): void
    {
        $user = $request->user();
        $current = app(CurrentWorkspace::class);
        $workspace = $current->get();
        $role = $current->role();

        if (! $user instanceof User || $workspace === null || $role === null || ! $role->isGuardPrivileged()) {
            return;
        }

        $hasSecondFactor = GuardEligibility::hasSecondFactor($user);

        if (WorkspaceProvisioner::settingsFor($workspace)->require_second_factor && ! $hasSecondFactor) {
            app(SecondFactorAlerts::class)->raise($workspace, $user);

            throw StepUpRequired::secondFactor();
        }

        if ($hasSecondFactor) {
            app(SecondFactorAlerts::class)->resolve($workspace, $user);
        }
    }
}
