<?php

namespace App\Providers;

use App\Services\Automation\AiAutomationActionExecutor;
use App\Services\Automation\AutomationActionExecutor;
use Illuminate\Support\ServiceProvider;

class AiAutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AutomationActionExecutor::class, AiAutomationActionExecutor::class);
    }
}
