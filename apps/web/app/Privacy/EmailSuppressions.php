<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Models\EmailSuppression;
use App\Models\Workspace;
use App\Notifications\RecipientOptedOut;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EmailSuppressions
{
    public function __construct(private Auditor $auditor) {}

    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function isSuppressed(string $email, ?int $workspaceId): bool
    {
        return EmailSuppression::query()
            ->whereRaw('lower(email) = ?', [self::normalize($email)])
            ->where(fn ($query) => $query->whereNull('workspace_id')->when(
                $workspaceId !== null,
                fn ($inner) => $inner->orWhere('workspace_id', $workspaceId),
            ))
            ->exists();
    }

    public function suppress(string $email, ?int $workspaceId, string $source = 'link'): bool
    {
        $email = self::normalize($email);
        $workspace = $workspaceId !== null ? Workspace::query()->find($workspaceId) : null;
        $workspaceId = $workspace?->id;

        if ($this->exactExists($email, $workspaceId)) {
            return false;
        }

        try {
            DB::transaction(function () use ($email, $workspace, $source): void {
                EmailSuppression::query()->create([
                    'email' => $email,
                    'workspace_id' => $workspace?->id,
                    'reason' => 'opt_out',
                    'source' => $source,
                ]);

                $this->auditor->record(
                    'email.opted_out',
                    $workspace,
                    metadata: ['source' => $source],
                    actor: null,
                    workspace: $workspace,
                    subjectLabel: self::mask($email),
                    platform: $workspace === null,
                    system: true,
                );
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($workspace !== null) {
            $owners = $workspace->members()->wherePivot('role', WorkspaceRole::Owner->value)->get();

            foreach ($owners as $owner) {
                $owner->notify(new RecipientOptedOut($workspace->id, self::mask($email)));
            }
        }

        return true;
    }

    public static function token(string $email, ?int $workspaceId): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'e' => self::normalize($email),
            'w' => $workspaceId,
        ])), '+/', '-_'), '=');

        return $payload.'.'.self::signature($payload);
    }

    /** @return array{email: string, workspace_id: int|null}|null */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || ! hash_equals(self::signature($parts[0]), $parts[1])) {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        if (! is_array($decoded) || ! is_string($decoded['e'] ?? null) || ! filter_var($decoded['e'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $workspaceId = $decoded['w'] ?? null;

        return ['email' => $decoded['e'], 'workspace_id' => is_int($workspaceId) ? $workspaceId : null];
    }

    public static function url(string $email, ?int $workspaceId): string
    {
        return route('email.opt-out', ['token' => self::token($email, $workspaceId)]);
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    private function exactExists(string $email, ?int $workspaceId): bool
    {
        return EmailSuppression::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where('workspace_id', $workspaceId)
            ->exists();
    }

    private static function signature(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'email-opt-out|'.$payload, (string) config('app.key'), true)), '+/', '-_'), '=');
    }
}
