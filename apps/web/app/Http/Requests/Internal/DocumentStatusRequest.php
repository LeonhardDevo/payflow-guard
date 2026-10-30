<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

final class DocumentStatusRequest extends InternalRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:processing,indexed,failed'],
            'pages' => ['nullable', 'integer', 'min:0'],
            'chunks' => ['nullable', 'integer', 'min:0'],
            'ocr_used' => ['nullable', 'boolean'],
            'error' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
