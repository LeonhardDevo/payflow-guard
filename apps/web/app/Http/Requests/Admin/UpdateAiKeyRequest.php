<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.ai') ?? false;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'api_key' => ['present', 'nullable', 'string', 'min:8', 'max:512', 'not_regex:/\s/'],
        ];
    }

    public function apiKey(): ?string
    {
        $key = $this->validated('api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
