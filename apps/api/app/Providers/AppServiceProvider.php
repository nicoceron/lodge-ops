<?php

namespace App\Providers;

use App\Models\Allocation;
use App\Models\AutomationRule;
use App\Models\Communication;
use App\Models\Deposit;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Observers\TenantAuditObserver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([
            Allocation::class,
            AutomationRule::class,
            Communication::class,
            Deposit::class,
            FolioLine::class,
            Guest::class,
            Membership::class,
            OperationalTask::class,
            Payment::class,
            Program::class,
            Property::class,
            Reservation::class,
            Resource::class,
            ResourceBlock::class,
        ] as $model) {
            $model::observe(TenantAuditObserver::class);
        }
    }
}
