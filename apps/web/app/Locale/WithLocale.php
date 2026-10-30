<?php

declare(strict_types=1);

namespace App\Locale;

use Closure;
use Illuminate\Support\Traits\Localizable;

final class WithLocale
{
    use Localizable;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(?string $locale, Closure $callback): mixed
    {
        return (new self)->withLocale($locale, $callback);
    }
}
