<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use App\Domain\Guard\Bank\BankRules;
use App\Domain\Shared\Time\Clock;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BankChangeRequest extends FormRequest
{
    public const array SOURCES = ['vendor_email', 'vendor_phone', 'vendor_portal', 'invoice_document', 'manual_entry'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $contact = ['nullable', 'string', 'max:255', 'required_if:source,vendor_email', 'required_if:source,vendor_phone'];

        if ($this->input('source') === 'vendor_email') {
            $contact[] = 'email';
        }

        if ($this->input('source') === 'vendor_phone') {
            $contact[] = 'regex:/^\+?[0-9 ()-]{7,20}$/';
        }

        return [
            ...self::bankRules(''),
            'source' => ['required', Rule::in(self::SOURCES)],
            'requester_contact' => $contact,
            'change_request_text' => ['nullable', 'string', 'max:10000'],
            'received_at' => ['nullable', 'date', 'before_or_equal:'.app(Clock::class)->now()->addMinute()->toIso8601String()],
        ];
    }

    /** @return array<string, mixed> */
    public static function bankRules(string $prefix): array
    {
        $currencies = array_keys((array) config('payflow.currencies', []));

        return [
            $prefix.'scheme' => ['required', Rule::in(['iban', 'us_ach', 'local'])],
            $prefix.'account_holder_name' => ['required', 'string', 'max:200'],
            $prefix.'iban' => ['nullable', 'string', 'max:42', 'required_if:'.$prefix.'scheme,iban', self::check(fn (string $value): bool => BankRules::ibanValid($value), 'iban_invalid')],
            $prefix.'account_number' => ['nullable', 'string', 'max:40', 'required_unless:'.$prefix.'scheme,iban'],
            $prefix.'routing_number' => ['nullable', 'string', 'max:20', 'required_if:'.$prefix.'scheme,us_ach'],
            $prefix.'swift_bic' => ['nullable', 'string', 'regex:'.BankRules::SWIFT_PATTERN],
            $prefix.'bank_name' => ['nullable', 'string', 'max:200'],
            $prefix.'bank_country' => ['required', 'string', 'size:2', 'alpha'],
            $prefix.'currency' => ['required', Rule::in($currencies)],
        ];
    }

    /** @param  Closure(string): bool  $test */
    private static function check(Closure $test, string $code): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($test, $code): void {
            if (is_string($value) && trim($value) !== '' && ! $test($value)) {
                $fail((string) __('payflow.errors.'.$code));
            }
        };
    }
}
