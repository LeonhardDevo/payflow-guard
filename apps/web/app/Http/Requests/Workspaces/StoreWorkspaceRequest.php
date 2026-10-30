<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Billing\Markets\MarketResolver;
use App\Legal\LegalAcceptances;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

final class StoreWorkspaceRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, ValidationRule|string|In>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'country' => ['required', 'string', 'size:2', Rule::in(app(MarketResolver::class)->countries())],
            'dpa' => app(LegalAcceptances::class)->workspaceKeys() !== [] ? ['accepted'] : ['nullable'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->filled('country')) {
            $this->merge(['country' => app(MarketResolver::class)->forRequest($this)->country]);
        }
    }
}
