<?php

namespace App\Providers;

use App\Models\Allocation;
use App\Models\AutomationRule;
use App\Models\CalendarFeed;
use App\Models\CatalogItem;
use App\Models\CommissionAccrual;
use App\Models\Communication;
use App\Models\CommunicationSuppression;
use App\Models\CostRecord;
use App\Models\CrmActivity;
use App\Models\DeliveryAttempt;
use App\Models\Deposit;
use App\Models\DocumentTemplate;
use App\Models\ExchangeRate;
use App\Models\FolioLine;
use App\Models\GeneratedDocument;
use App\Models\Guest;
use App\Models\GuestMergeAlias;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\OperationalTask;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Property;
use App\Models\ReportExport;
use App\Models\Reservation;
use App\Models\ReservationNote;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ResourceCategory;
use App\Models\RetailSale;
use App\Models\RetailSaleLine;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Observers\TenantAuditObserver;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::createUrlUsing(
            fn ($user, string $token): string => Filament::getPanel('admin')->getResetPasswordUrl($token, $user),
        );

        foreach ([
            Allocation::class,
            AutomationRule::class,
            CatalogItem::class,
            CalendarFeed::class,
            CommissionAccrual::class,
            Communication::class,
            CommunicationSuppression::class,
            CostRecord::class,
            CrmActivity::class,
            DeliveryAttempt::class,
            Deposit::class,
            DocumentTemplate::class,
            ExchangeRate::class,
            FolioLine::class,
            GeneratedDocument::class,
            Guest::class,
            GuestMergeAlias::class,
            IntegrationConnection::class,
            Membership::class,
            MessageTemplate::class,
            MessageTemplateVersion::class,
            OperationalTask::class,
            Opportunity::class,
            Organization::class,
            Payment::class,
            Program::class,
            Property::class,
            ReportExport::class,
            Reservation::class,
            ReservationNote::class,
            Resource::class,
            ResourceBlock::class,
            ResourceCategory::class,
            RetailSale::class,
            RetailSaleLine::class,
            StockLocation::class,
            StockMovement::class,
        ] as $model) {
            $model::observe(TenantAuditObserver::class);
        }
    }
}
