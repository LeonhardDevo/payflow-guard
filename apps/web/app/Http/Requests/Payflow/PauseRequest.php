<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use Illuminate\Foundation\Http\FormRequest;

final class PauseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'paused' => ['required', 'boolean'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
            'acknowledge_opt_out' => ['sometimes', 'boolean'],
        ];
    }
}
