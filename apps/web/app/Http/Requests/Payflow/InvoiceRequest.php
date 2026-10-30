<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyParser;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function isOpenEdit(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice && in_array($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid], true);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $invoice = $this->route('invoice');

        if ($this->isOpenEdit() && $invoice instanceof Invoice) {
            return [
                'notes' => ['nullable', 'string', 'max:2000'],
                'terms' => ['nullable', 'string', 'max:2000'],
                'po_number' => ['nullable', 'string', 'max:64'],
                'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$invoice->issueDate()],
            ];
        }

        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'number' => [
                'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_\/.]+$/',
                Rule::unique('invoices', 'number')->where('workspace_id', $workspaceId)->ignore($invoice instanceof Invoice ? $invoice->id : null),
            ],
            'currency' => ['required', 'string', Rule::in(array_keys((array) config('payflow.currencies')))],
            'locale' => ['required', Rule::in(['en', 'ru'])],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'po_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'items.*.unit_price' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'items.*.tax_rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'between:0,100'],
            'action' => ['required', Rule::in(['save_draft', 'save_and_send', 'save_and_mark_sent'])],
        ];
    }

    /** @return array{client_id: int, number: string|null, currency: string, locale: string, issue_date: string, due_date: string, po_number: string|null, notes: string|null, terms: string|null, items: list<array{description: string, quantity: string, unit_price_minor: int, tax_rate_bp: int}>} */
    public function draftData(): array
    {
        $items = [];

        foreach ((array) $this->input('items', []) as $item) {
            $item = (array) $item;
            $taxRate = isset($item['tax_rate']) && $item['tax_rate'] !== '' ? (string) $item['tax_rate'] : '0';
            $items[] = [
                'description' => trim((string) $item['description']),
                'quantity' => (string) $item['quantity'],
                'unit_price_minor' => MoneyParser::toMinor((string) $item['unit_price']),
                'tax_rate_bp' => min(10000, MoneyParser::percentToBasisPoints($taxRate)),
            ];
        }

        return [
            'client_id' => (int) $this->input('client_id'),
            'number' => $this->filled('number') ? (string) $this->input('number') : null,
            'currency' => strtoupper((string) $this->input('currency')),
            'locale' => (string) $this->input('locale'),
            'issue_date' => (string) $this->input('issue_date'),
            'due_date' => (string) $this->input('due_date'),
            'po_number' => $this->filled('po_number') ? (string) $this->input('po_number') : null,
            'notes' => $this->filled('notes') ? (string) $this->input('notes') : null,
            'terms' => $this->filled('terms') ? (string) $this->input('terms') : null,
            'items' => $items,
        ];
    }

    /** @return array{notes: string|null, terms: string|null, po_number: string|null, due_date: string} */
    public function openData(): array
    {
        return [
            'notes' => $this->filled('notes') ? (string) $this->input('notes') : null,
            'terms' => $this->filled('terms') ? (string) $this->input('terms') : null,
            'po_number' => $this->filled('po_number') ? (string) $this->input('po_number') : null,
            'due_date' => (string) $this->input('due_date'),
        ];
    }
}
