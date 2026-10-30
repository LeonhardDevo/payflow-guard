<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\AiAdminController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

final class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.ai') ?? false;
    }

    /** @return array<string, array<int, ValidationRule|string|In>> */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            'model' => ['required', 'string', 'max:160'],
            'fallback_model' => ['nullable', 'string', 'max:160'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'embeddings' => ['required', 'array'],
            'embeddings.provider' => ['required', 'string', Rule::in(AiAdminController::EMBEDDINGS_PROVIDERS)],
            'embeddings.model' => ['required', 'string', 'max:160'],
        ];
    }

    /** @return array{provider: string, model: string, fallback_model: string|null, temperature: float, embeddings: array{provider: string, model: string}} */
    public function settings(): array
    {
        return [
            'provider' => (string) $this->validated('provider'),
            'model' => trim((string) $this->validated('model')),
            'fallback_model' => filled($this->validated('fallback_model')) ? trim((string) $this->validated('fallback_model')) : null,
            'temperature' => (float) ($this->validated('temperature') ?? 0.2),
            'embeddings' => [
                'provider' => (string) $this->validated('embeddings.provider'),
                'model' => trim((string) $this->validated('embeddings.model')),
            ],
        ];
    }
}
