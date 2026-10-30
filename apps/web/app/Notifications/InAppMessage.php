<?php

declare(strict_types=1);

namespace App\Notifications;

use InvalidArgumentException;

final readonly class InAppMessage
{
    public const array KINDS = ['info', 'success', 'warning', 'danger', 'ai'];

    /**
     * @param  array<string, string|int|float>  $titleParams
     * @param  array<string, string|int|float>  $bodyParams
     */
    public function __construct(
        public string $kind,
        public string $titleKey,
        public array $titleParams = [],
        public ?string $bodyKey = null,
        public array $bodyParams = [],
        public ?string $href = null,
        public ?int $workspaceId = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown in-app notification kind [{$kind}].");
        }

        if ($href !== null && (! str_starts_with($href, '/') || str_starts_with($href, '//'))) {
            throw new InvalidArgumentException('In-app notification links must be app-relative paths.');
        }
    }

    /** @return array{kind: string, title: array{key: string, params: array<string, string|int|float>}, body: array{key: string, params: array<string, string|int|float>}|null, href: string|null, workspace_id: int|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'title' => ['key' => $this->titleKey, 'params' => $this->titleParams],
            'body' => $this->bodyKey !== null ? ['key' => $this->bodyKey, 'params' => $this->bodyParams] : null,
            'href' => $this->href,
            'workspace_id' => $this->workspaceId,
        ];
    }
}
