<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

final class TelegramUnlinkRequest extends InternalRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'telegram_user_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
