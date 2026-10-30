<?php

declare(strict_types=1);

namespace App\Http\Requests\Payflow;

use Illuminate\Foundation\Http\FormRequest;

final class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:255']];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
