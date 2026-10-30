<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Billing\Markets\MarketResolver;
use App\Locale\Locales;
use App\Support\Options;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

abstract class WorkspaceRequest extends FormRequest
{
    /** @return array<string, array<int, ValidationRule|string|In>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'timezone' => ['required', 'string', Rule::in(Options::timezones())],
            'currency' => ['required', 'string', 'size:3', Rule::in(Options::currencies())],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(Locales::supported())],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', Rule::in(app(MarketResolver::class)->countries())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'currency' => is_string($this->input('currency')) ? strtoupper($this->input('currency')) : $this->input('currency'),
            'country' => is_string($this->input('country')) ? strtoupper($this->input('country')) : $this->input('country'),
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => (string) __('validation.attributes.workspace_name'),
        ];
    }
}
