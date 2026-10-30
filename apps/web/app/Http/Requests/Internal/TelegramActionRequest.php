<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

final class TelegramActionRequest extends InternalRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'payload' => ['nullable', 'array'],
            'telegram_user_id' => ['required', 'integer', 'min:1'],
            'chat_id' => ['required', 'integer'],
            'workspace_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'locale' => ['nullable', 'string', 'max:16'],
        ];
    }
}
