<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PayflowServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    PayflowServiceProvider::class,
];
