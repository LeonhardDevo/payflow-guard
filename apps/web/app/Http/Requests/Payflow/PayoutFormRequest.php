<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use App\Domain\Shared\Money\MoneyParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PayoutFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'currency' => ['required', Rule::in(array_keys((array) config('payflow.currencies', [])))],
            'description' => ['required', 'string', 'max:500'],
            'vendor_invoice_number' => ['nullable', 'string', 'max:100'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'submit' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{vendor_id: int, amount_minor: int, currency: string, description: string, vendor_invoice_number: string|null, due_date: string|null} */
    public function payload(): array
    {
        $number = trim((string) $this->input('vendor_invoice_number', ''));

        return [
            'vendor_id' => (int) $this->input('vendor_id'),
            'amount_minor' => MoneyParser::toMinor((string) $this->input('amount')),
            'currency' => strtoupper((string) $this->input('currency')),
            'description' => trim((string) $this->input('description')),
            'vendor_invoice_number' => $number === '' ? null : $number,
            'due_date' => $this->filled('due_date') ? (string) $this->input('due_date') : null,
        ];
    }
}
