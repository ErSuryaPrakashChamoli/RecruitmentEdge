<?php

use App\Providers\AiServiceProvider;
use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;
use App\Providers\TenancyServiceProvider;

return [
    TenancyServiceProvider::class,
    AppServiceProvider::class,
    AiServiceProvider::class,
    ApiServiceProvider::class,
    AdminPanelProvider::class,
    PlatformPanelProvider::class,
];
