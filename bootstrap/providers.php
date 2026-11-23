<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\MonitoringServiceProvider;
use App\Providers\ValidationServiceProvider;

return [
    AppServiceProvider::class,
    MonitoringServiceProvider::class,
    ValidationServiceProvider::class,
];
