<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use Illuminate\Pagination\LengthAwarePaginator;

final class Paginated
{
    /**
     * @template T
     *
     * @param  LengthAwarePaginator<int, T>  $paginator
     * @param  array<int, array<string, mixed>>  $data
     * @return array{data: list<array<string, mixed>>, links: list<array{url: string|null, label: string, active: bool}>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public static function of(LengthAwarePaginator $paginator, array $data): array
    {
        $links = [];

        foreach ($paginator->linkCollection() as $link) {
            $links[] = [
                'url' => is_string($link['url'] ?? null) ? $link['url'] : null,
                'label' => strip_tags((string) ($link['label'] ?? '')),
                'active' => (bool) ($link['active'] ?? false),
            ];
        }

        return [
            'data' => array_values($data),
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
