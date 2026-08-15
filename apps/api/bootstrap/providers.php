<?php

use App\Providers\AiAutomationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AiAutomationServiceProvider::class,
    AdminPanelProvider::class,
];
