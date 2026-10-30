<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

final class Paginated
{
    /**
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $paginator
     * @param  \Closure(TItem): array<string, mixed>  $map
     * @return array{data: list<array<string, mixed>>, links: list<array{url: string|null, label: string, active: bool}>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public static function from(LengthAwarePaginator $paginator, \Closure $map): array
    {
        /** @var list<array{url: string|null, label: string, active: bool}> $links */
        $links = array_values(array_map(
            fn (array $link): array => [
                'url' => $link['url'] ?? null,
                'label' => html_entity_decode(strip_tags((string) $link['label']), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'active' => (bool) ($link['active'] ?? false),
            ],
            $paginator->linkCollection()->all(),
        ));

        return [
            'data' => array_values(array_map($map, $paginator->items())),
            'links' => $links,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ];
    }
}
