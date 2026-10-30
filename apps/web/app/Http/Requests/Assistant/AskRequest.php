<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class AskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistant.use') ?? false;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
        ];
    }
}
