<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Audit\Auditor;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

final readonly class AuditAuthenticationEvents
{
    public function __construct(private Auditor $auditor) {}

    public function onLogin(Login $event): void
    {
        $this->forUser('auth.login', $event->user, ['remember' => $event->remember]);
    }

    public function onLogout(Logout $event): void
    {
        $this->forUser('auth.logout', $event->user);
    }

    public function onFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $user = $event->user instanceof User ? $event->user : null;

        $this->auditor->record(
            'auth.login_failed',
            $user,
            metadata: ['email' => is_string($email) ? mb_strtolower($email) : null],
            actor: null,
            workspace: $user?->current_workspace_id,
            subjectLabel: is_string($email) ? $email : null,
        );
    }

    public function onLockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $this->auditor->record(
            'auth.lockout',
            metadata: ['email' => is_string($email) ? mb_strtolower($email) : null],
            actor: null,
            workspace: null,
            subjectLabel: is_string($email) ? $email : null,
        );
    }

    public function onRegistered(Registered $event): void
    {
        $this->forUser('auth.registered', $event->user);
    }

    public function onVerified(Verified $event): void
    {
        $this->forUser('auth.email_verified', $event->user);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->forUser('auth.password_reset', $event->user);
    }

    public function onTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->forUser('auth.two_factor_enabled', $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->forUser('auth.two_factor_disabled', $event->user);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Registered::class => 'onRegistered',
            Verified::class => 'onVerified',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationEnabled::class => 'onTwoFactorEnabled',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
        ];
    }

    /** @param  array<string, mixed>  $metadata */
    private function forUser(string $action, mixed $user, array $metadata = []): void
    {
        if (! $user instanceof User) {
            return;
        }

        $this->auditor->record(
            $action,
            $user,
            metadata: $metadata,
            actor: $user,
            workspace: $user->current_workspace_id,
            subjectLabel: $user->email,
        );
    }
}
