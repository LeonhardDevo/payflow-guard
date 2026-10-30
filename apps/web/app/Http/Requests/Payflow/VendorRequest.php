<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use App\Domain\Guard\Enums\VendorCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'category' => ['required', Rule::enum(VendorCategory::class)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'default_currency' => ['required', Rule::in(array_keys((array) config('payflow.currencies', [])))],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        if ($this->isMethod('post') && is_array($this->input('bank')) && $this->input('bank') !== []) {
            $rules['bank'] = ['array'];
            $rules = [...$rules, ...BankChangeRequest::bankRules('bank.')];
        }

        return $rules;
    }
}
