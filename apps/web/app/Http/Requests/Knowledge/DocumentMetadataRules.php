<?php

declare(strict_types=1);

namespace App\Http\Requests\Knowledge;

use App\Enums\WorkspaceRole;
use Illuminate\Validation\Rule;

trait DocumentMetadataRules
{
    /** @return array<string, array<int, mixed>> */
    protected function metadataRules(bool $titleRequired): array
    {
        return [
            'title' => [$titleRequired ? 'required' : 'nullable', 'string', 'max:250'],
            'department' => ['nullable', 'string', 'max:120'],
            'roles' => ['nullable', 'array', 'max:20'],
            'roles.*' => ['string', 'distinct', Rule::in(WorkspaceRole::values())],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'distinct', 'max:40'],
            'version' => ['nullable', 'string', 'max:40'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array{title?: string|null, department?: string|null, roles?: list<string>, tags?: list<string>, version?: string|null, effective_date?: string|null} */
    public function metadata(): array
    {
        $data = $this->safe()->only(['title', 'department', 'roles', 'tags', 'version', 'effective_date']);

        foreach (['roles', 'tags'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_filter(
                    array_map(fn (mixed $value): string => trim((string) $value), (array) ($data[$list] ?? [])),
                    fn (string $value): bool => $value !== '',
                ));
            }
        }

        foreach (['department', 'version'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) === '' ? null : trim($data[$field]);
            }
        }

        /** @var array{title?: string|null, department?: string|null, roles?: list<string>, tags?: list<string>, version?: string|null, effective_date?: string|null} $data */
        return $data;
    }
}
