<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\MetricsServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    MetricsServiceProvider::class,
];
