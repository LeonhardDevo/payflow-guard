<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Receivables\Enums\ToneCeiling;
use App\Domain\Receivables\Models\Client;
use App\Domain\Shared\Money\MoneyParser;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $client = $this->route('client');
        $ignore = $client instanceof Client ? $client->id : null;

        return [
            'name' => ['required', 'string', 'max:200', Rule::unique('clients', 'name')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->ignore($ignore)],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'cc_emails' => ['nullable', 'array', 'max:5'],
            'cc_emails.*' => ['email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'timezone' => ['nullable', 'timezone:all'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:1000'],
            'locale' => ['required', Rule::in(['en', 'ru'])],
            'currency' => ['required', Rule::in(array_keys((array) config('payflow.currencies')))],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,180'],
            'reminder_policy_id' => ['nullable', 'integer', Rule::exists('reminder_policies', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'tone_ceiling' => ['required', Rule::enum(ToneCeiling::class)],
            'late_fee_override' => ['sometimes', 'boolean'],
            'late_fee.type' => ['required_if:late_fee_override,true', 'nullable', Rule::enum(LateFeeType::class)],
            'late_fee.rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'between:0,100'],
            'late_fee.fixed' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'late_fee.grace_days' => ['nullable', 'integer', 'between:0,90'],
            'late_fee.cap' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'between:0,100'],
            'contract_document_id' => ['nullable', 'integer', Rule::exists('documents', 'id')->where('workspace_id', $workspaceId)],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public function clientData(): array
    {
        $override = $this->boolean('late_fee_override');
        $fee = (array) $this->input('late_fee', []);
        $type = $override && isset($fee['type']) ? LateFeeType::from((string) $fee['type']) : null;
        $percent = fn (string $key): ?int => isset($fee[$key]) && $fee[$key] !== '' ? MoneyParser::percentToBasisPoints((string) $fee[$key]) : null;

        return [
            'name' => trim((string) $this->input('name')),
            'legal_name' => $this->nullable('legal_name'),
            'contact_name' => $this->nullable('contact_name'),
            'email' => $this->nullable('email'),
            'cc_emails' => array_values(array_map('strval', (array) $this->input('cc_emails', []))),
            'phone' => $this->nullable('phone'),
            'country' => $this->filled('country') ? strtoupper((string) $this->input('country')) : null,
            'timezone' => $this->nullable('timezone'),
            'tax_id' => $this->nullable('tax_id'),
            'address' => $this->nullable('address'),
            'locale' => (string) $this->input('locale'),
            'currency' => strtoupper((string) $this->input('currency')),
            'payment_terms_days' => $this->filled('payment_terms_days') ? (int) $this->input('payment_terms_days') : null,
            'reminder_policy_id' => $this->filled('reminder_policy_id') ? (int) $this->input('reminder_policy_id') : null,
            'tone_ceiling' => ToneCeiling::from((string) $this->input('tone_ceiling')),
            'late_fee_type' => $type,
            'late_fee_rate_bp' => $type !== null ? $percent('rate') : null,
            'late_fee_fixed_minor' => $type !== null && isset($fee['fixed']) && $fee['fixed'] !== '' ? MoneyParser::toMinor((string) $fee['fixed']) : null,
            'late_fee_grace_days' => $type !== null ? (int) ($fee['grace_days'] ?? 0) : null,
            'late_fee_cap_bp' => $type !== null ? $percent('cap') : null,
            'contract_document_id' => $this->filled('contract_document_id') ? (int) $this->input('contract_document_id') : null,
            'owner_user_id' => $this->filled('owner_user_id') ? (int) $this->input('owner_user_id') : null,
            'tags' => array_values(array_unique(array_map(fn (mixed $tag): string => trim((string) $tag), (array) $this->input('tags', [])))),
            'notes' => $this->nullable('notes'),
        ];
    }

    private function nullable(string $key): ?string
    {
        return $this->filled($key) ? trim((string) $this->input($key)) : null;
    }
}
