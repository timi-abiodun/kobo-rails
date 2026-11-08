<?php

use App\Providers\AppServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\NameMatchingServiceProvider;
use App\Providers\WaasServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    NameMatchingServiceProvider::class,
    WaasServiceProvider::class,
];
