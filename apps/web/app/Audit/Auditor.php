<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Support\RequestId;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class Auditor
{
    private const string REDACTED = '[redacted]';

    private const string SECRET_KEY_PATTERN = '/(pass(word)?|secret|token|api[_-]?key|private[_-]?key|credential)/i';

    public function __construct(
        private AuthFactory $auth,
        private CurrentWorkspace $currentWorkspace,
        private RequestId $requestId,
        private Container $container,
    ) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        array $metadata = [],
        ?User $actor = null,
        Workspace|int|null $workspace = null,
        ?string $subjectLabel = null,
        bool $platform = false,
        bool $system = false,
    ): AuditLog {
        $actor ??= $system ? null : $this->resolveActor();
        $request = $this->request();

        $entry = new AuditLog;
        $entry->fill([
            'workspace_id' => $platform ? null : $this->resolveWorkspaceId($workspace, $subject),
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'subject_type' => $subject !== null ? self::subjectType($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'subject_label' => $subjectLabel !== null ? Str::limit($subjectLabel, 250) : null,
            'changes' => $changes === [] ? null : $this->redactChanges($changes),
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'request_id' => $this->requestId->get(),
        ]);
        $entry->save();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $only
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(array $before, array $after, array $only = []): array
    {
        $keys = $only !== [] ? $only : array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $diff = [];

        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old instanceof \BackedEnum) {
                $old = $old->value;
            }

            if ($new instanceof \BackedEnum) {
                $new = $new->value;
            }

            if ($old != $new) {
                $diff[$key] = [$old, $new];
            }
        }

        return $diff;
    }

    public static function subjectType(Model $subject): string
    {
        return Str::snake(class_basename($subject));
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function redactChanges(array $changes): array
    {
        foreach ($changes as $key => $pair) {
            if (preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $changes[$key] = [
                    $pair[0] === null ? null : self::REDACTED,
                    $pair[1] === null ? null : self::REDACTED,
                ];
            }
        }

        return $changes;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $data[$key] = $value === null ? null : self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function resolveActor(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }

    private function resolveWorkspaceId(Workspace|int|null $workspace, ?Model $subject): ?int
    {
        if ($workspace instanceof Workspace) {
            return $workspace->id;
        }

        if (is_int($workspace)) {
            return $workspace;
        }

        if ($subject instanceof Workspace) {
            return $subject->id;
        }

        $subjectWorkspace = $subject?->getAttribute('workspace_id');

        if (is_numeric($subjectWorkspace)) {
            return (int) $subjectWorkspace;
        }

        return $this->currentWorkspace->id();
    }

    private function request(): ?Request
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return $this->container->make(Request::class);
    }
}
