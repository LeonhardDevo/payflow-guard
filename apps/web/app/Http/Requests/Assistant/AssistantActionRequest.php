<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class AssistantActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistant.use') ?? false;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'payload' => ['nullable', 'array', 'max:20'],
            'payload.*' => ['nullable'],
        ];
    }

    /** @return array<string, string|int|float|bool|null> */
    public function payload(): array
    {
        $payload = $this->validated('payload');
        $clean = [];

        foreach (is_array($payload) ? $payload : [] as $key => $value) {
            if (is_string($key) && ($value === null || is_scalar($value))) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 2000) : $value;
            }
        }

        return $clean;
    }
}
