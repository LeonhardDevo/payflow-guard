<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Privacy\RetentionPolicy;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateWorkspacePrivacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(CurrentWorkspace::class)->get();

        return $workspace !== null && ($this->user()?->can('update', $workspace) ?? false);
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        $policy = app(RetentionPolicy::class);
        $workspace = app(CurrentWorkspace::class)->get();
        $rules = [
            'ai_external_allowed' => ['sometimes', 'boolean'],
            'retention' => ['sometimes', 'array:'.implode(',', array_keys($policy->overridable()))],
        ];

        foreach (array_keys($policy->overridable()) as $key) {
            $bounds = $policy->bounds($key, $workspace);
            $rules["retention.{$key}"] = ['sometimes', 'nullable', 'integer', 'min:'.($bounds['min'] ?? 1), 'max:'.($bounds['max'] ?? 3650)];
        }

        return $rules;
    }
}
