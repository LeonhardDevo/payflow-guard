<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspace = app(CurrentWorkspace::class)->get();
        $today = WorkspaceClock::todayIn($workspace->timezone ?? 'UTC', app(Clock::class)->now())->toDateString();

        return [
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'method' => ['required', Rule::in(['bank_transfer', 'card', 'cash', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'send_receipt' => ['sometimes', 'boolean'],
            'allow_overpayment' => ['sometimes', 'boolean'],
        ];
    }
}
