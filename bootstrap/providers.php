<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\TenancyServiceProvider;

return [
    TenancyServiceProvider::class,
    AppServiceProvider::class,
    AiServiceProvider::class,
    AdminPanelProvider::class,
];
