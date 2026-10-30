<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use App\Domain\Guard\Enums\VerificationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VerifyBankChangeRequest extends FormRequest
{
    public const array CHECKLIST = ['called_known_number', 'confirmed_holder_name', 'confirmed_last4', 'confirmed_request_origin'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'method' => ['required', Rule::enum(VerificationMethod::class)],
            'checklist' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:2000'],
            'solo' => ['sometimes', 'boolean'],
        ];

        foreach (self::CHECKLIST as $key) {
            $rules['checklist.'.$key] = ['accepted'];
        }

        return $rules;
    }

    /** @return array<string, bool> */
    public function checklist(): array
    {
        $result = [];

        foreach (self::CHECKLIST as $key) {
            $result[$key] = $this->boolean('checklist.'.$key);
        }

        return $result;
    }
}
