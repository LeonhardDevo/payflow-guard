<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use App\Models\User;

final class UserRef
{
    /** @return array{id: int, name: string}|null */
    public static function of(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->id, 'name' => self::name($user)];
    }

    public static function name(User $user): string
    {
        if ($user->getAttribute('pseudonymised_at') !== null) {
            return (string) trans('payflow.users.deleted', ['id' => $user->id]);
        }

        return $user->name;
    }
}
