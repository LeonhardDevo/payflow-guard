<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

final class DeliveryRequest extends InternalRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'array'],
            'reference.type' => ['required', 'string', 'max:64'],
            'reference.id' => ['required', 'max:64'],
            'channel' => ['required', 'string', 'in:email,telegram'],
            'status' => ['required', 'string', 'in:deferred,sent,failed'],
            'error' => ['nullable', 'string', 'max:5000'],
            'provider_message_id' => ['nullable', 'string', 'max:255'],
            'attempt' => ['nullable', 'integer', 'min:1', 'max:100'],
            'next_retry_at' => ['nullable', 'date'],
        ];
    }
}
