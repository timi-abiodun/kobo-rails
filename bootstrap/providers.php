<?php

use App\Providers\AppServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\NameMatchingServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    NameMatchingServiceProvider::class,
];
