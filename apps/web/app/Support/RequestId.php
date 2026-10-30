<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class RequestId
{
    public const string HEADER = 'X-Request-Id';

    private ?string $id = null;

    public function get(): string
    {
        return $this->id ??= (string) Str::uuid();
    }

    public function set(string $id): void
    {
        $this->id = $id;
    }

    public static function isValid(?string $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $id) === 1;
    }
}
