<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['remind', 'pause', 'resume', 'mark_sent'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map(fn (mixed $id): int => (int) $id, (array) $this->input('ids', [])));
    }
}
