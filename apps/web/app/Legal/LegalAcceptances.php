<?php

declare(strict_types=1);

namespace App\Legal;

use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class LegalAcceptances
{
    public function __construct(
        private LegalRepository $legal,
        private Auditor $auditor,
        private Config $config,
    ) {}

    /** @return list<string> */
    public function registrationKeys(string $locale): array
    {
        $keys = [
            ...(array) $this->config->get('legal.acceptance.registration', []),
            ...(array) $this->config->get("legal.acceptance.registration_locale.{$locale}", []),
        ];

        return array_values(array_unique(array_filter($keys, fn (mixed $key): bool => is_string($key) && $this->legal->exists($key))));
    }

    /** @return list<string> */
    public function workspaceKeys(): array
    {
        return array_values(array_filter(
            (array) $this->config->get('legal.acceptance.workspace', []),
            fn (mixed $key): bool => is_string($key) && $this->legal->exists($key),
        ));
    }

    /** @return array<string, array{version: string, url: string, title: string}|null> */
    public function registrationProps(string $locale): array
    {
        $props = [];

        foreach (['terms', 'privacy', 'consent-ru'] as $key) {
            $props[str_replace('-', '_', $key)] = null;
        }

        foreach ($this->registrationKeys($locale) as $key) {
            $props[str_replace('-', '_', $key)] = $this->linkProp($key, $locale);
        }

        return $props;
    }

    /** @return array{version: string, url: string, title: string}|null */
    public function workspaceProp(string $locale): ?array
    {
        $key = $this->workspaceKeys()[0] ?? null;

        return $key !== null ? $this->linkProp($key, $locale) : null;
    }

    public function acceptRegistration(User $user, string $locale, ?Request $request): void
    {
        foreach ($this->registrationKeys($locale) as $key) {
            $this->record($user, $key, null, $locale, $request);
        }
    }

    public function acceptWorkspace(User $user, Workspace $workspace, string $locale, ?Request $request): void
    {
        foreach ($this->workspaceKeys() as $key) {
            $this->record($user, $key, $workspace, $locale, $request);
        }
    }

    public function record(User $user, string $key, ?Workspace $workspace, string $locale, ?Request $request): ?LegalAcceptance
    {
        $document = $this->legal->metadata($key, $locale);

        if ($document === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $document, $workspace, $locale, $request): LegalAcceptance {
            $acceptance = LegalAcceptance::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace?->id,
                'document' => $document->key,
                'version' => $document->version,
                'locale' => $locale,
                'accepted_at' => now(),
                'ip' => $request?->ip(),
                'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 500, '') : null,
            ]);

            $this->auditor->record(
                'legal.accepted',
                $workspace ?? $user,
                metadata: ['document' => $document->key, 'version' => $document->version, 'locale' => $locale],
                actor: $user,
                workspace: $workspace,
                subjectLabel: $document->key.' '.$document->version,
                platform: $workspace === null,
            );

            return $acceptance;
        });
    }

    /** @return list<array{document: LegalDocument, workspace: Workspace|null}> */
    public function pending(User $user, ?Workspace $workspace, string $locale): array
    {
        $pending = [];
        $latest = $this->latestVersions($user);

        $keys = $this->registrationKeys($user->locale);
        $base = (array) $this->config->get('legal.acceptance.registration', []);
        $registered = array_filter($base, fn (mixed $key): bool => is_string($key) && isset($latest[$key.'|'])) !== [];

        $keys = array_values(array_filter(
            $keys,
            fn (string $key): bool => in_array($key, $base, true) || ! $registered || isset($latest[$key.'|']),
        ));

        foreach ((array) $this->config->get('legal.acceptance.registration_locale', []) as $localeKeys) {
            foreach ((array) $localeKeys as $key) {
                if (is_string($key) && isset($latest[$key.'|']) && ! in_array($key, $keys, true) && $this->legal->exists($key)) {
                    $keys[] = $key;
                }
            }
        }

        foreach ($keys as $key) {
            $document = $this->legal->metadata($key, $locale);

            if ($document !== null && $this->needsAcceptance($document, $latest[$key.'|'] ?? null)) {
                $pending[] = ['document' => $document, 'workspace' => null];
            }
        }

        if ($workspace !== null && $user->roleIn($workspace) === WorkspaceRole::Owner) {
            foreach ($this->workspaceKeys() as $key) {
                $document = $this->legal->metadata($key, $locale);

                if ($document !== null && $this->needsAcceptance($document, $latest[$key.'|'.$workspace->id] ?? null)) {
                    $pending[] = ['document' => $document, 'workspace' => $workspace];
                }
            }
        }

        return $pending;
    }

    public function acceptPending(User $user, ?Workspace $workspace, string $locale, ?Request $request): int
    {
        $pending = $this->pending($user, $workspace, $locale);

        foreach ($pending as $item) {
            $this->record($user, $item['document']->key, $item['workspace'], $locale, $request);
        }

        return count($pending);
    }

    /** @return array<int, array{document: string, version: string, accepted_at: string, workspace_id: int|null}> */
    public function history(User $user, int $limit = 50): array
    {
        return LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (LegalAcceptance $acceptance): array => [
                'document' => $acceptance->document,
                'version' => $acceptance->version,
                'accepted_at' => $acceptance->accepted_at->toIso8601String(),
                'workspace_id' => $acceptance->workspace_id,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function latestVersions(User $user): array
    {
        $rows = LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get(['document', 'version', 'workspace_id']);

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->document.'|'.($row->workspace_id ?? '')] = $row->version;
        }

        return $latest;
    }

    private function needsAcceptance(LegalDocument $document, ?string $acceptedVersion): bool
    {
        if ($acceptedVersion === null) {
            return true;
        }

        return $acceptedVersion !== $document->version && $document->requiresAcceptance;
    }

    /** @return array{version: string, url: string, title: string}|null */
    private function linkProp(string $key, string $locale): ?array
    {
        $document = $this->legal->metadata($key, $locale);

        return $document !== null
            ? ['version' => $document->version, 'url' => $document->url(), 'title' => $document->title]
            : null;
    }
}
