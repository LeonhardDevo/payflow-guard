<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Billing\PlanCatalog;
use App\Enums\BillingInterval;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;

final class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.manage') ?? false;
    }

    /** @return array<string, array<int, ValidationRule|string|In|Enum>> */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::in(app(PlanCatalog::class)->paidKeys())],
            'interval' => ['required', 'string', Rule::enum(BillingInterval::class)],
        ];
    }

    public function billingInterval(): BillingInterval
    {
        return BillingInterval::from((string) $this->validated('interval'));
    }
}
