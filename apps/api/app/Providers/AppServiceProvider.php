<?php

namespace App\Providers;

use App\Contracts\Documents\DocumentRenderer;
use App\Contracts\Fiscal\FiscalSourceSnapshotFactory;
use App\Contracts\Integrations\SecretReferenceResolver;
use App\Contracts\Payments\PaymentGatewayFactory;
use App\Integrations\Payments\MercadoPago\DefaultPaymentGatewayFactory;
use App\Integrations\Secrets\EnvironmentSecretReferenceResolver;
use App\Models\Allocation;
use App\Models\AutomationRule;
use App\Models\CalendarFeed;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\CatalogItem;
use App\Models\CommercialPromotion;
use App\Models\CommissionAccrual;
use App\Models\Communication;
use App\Models\CommunicationSuppression;
use App\Models\CostRecord;
use App\Models\CrmActivity;
use App\Models\DeliveryAttempt;
use App\Models\Deposit;
use App\Models\DepositPolicy;
use App\Models\DocumentGenerationRequest;
use App\Models\DocumentTemplate;
use App\Models\ExchangeRate;
use App\Models\FiscalSourceSnapshot;
use App\Models\FolioLine;
use App\Models\GeneratedDocument;
use App\Models\Guest;
use App\Models\GuestMergeAlias;
use App\Models\GuestPaymentEvidence;
use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionCapability;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationEndpointKey;
use App\Models\IntegrationEvent;
use App\Models\IntegrationMapping;
use App\Models\IntegrationOperation;
use App\Models\IntegrationReconciliation;
use App\Models\IntegrationSyncCursor;
use App\Models\IntegrationSyncRun;
use App\Models\IntegrationSyncRunItem;
use App\Models\Membership;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\OperationalTask;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRequest;
use App\Models\Program;
use App\Models\Property;
use App\Models\ProviderDispute;
use App\Models\ProviderDisputeRevision;
use App\Models\ProviderEvent;
use App\Models\ProviderRefund;
use App\Models\RatePlan;
use App\Models\RatePlanService;
use App\Models\RateRule;
use App\Models\ReportExport;
use App\Models\Reservation;
use App\Models\ReservationNote;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ResourceCategory;
use App\Models\RetailSale;
use App\Models\RetailSaleLine;
use App\Models\SettlementEntry;
use App\Models\SettlementEntryRevision;
use App\Models\SettlementReportImport;
use App\Models\SettlementReportRow;
use App\Models\SettlementVarianceAction;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\TaxRule;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\VoucherRedemptionEvent;
use App\Observers\TenantAuditObserver;
use App\Services\Documents\SpatieDocumentRenderer;
use App\Services\Fiscal\DatabaseFiscalSourceSnapshotFactory;
use App\Services\Integrations\CapabilityPortRegistry;
use App\Services\Integrations\EndpointKeyRuntimeStore;
use App\Services\Payments\SensitivePaymentDataGuard;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext);
        $this->app->scoped(SensitivePaymentDataGuard::class, fn (): SensitivePaymentDataGuard => new SensitivePaymentDataGuard);
        $this->app->bind(DocumentRenderer::class, SpatieDocumentRenderer::class);
        $this->app->bind(PaymentGatewayFactory::class, DefaultPaymentGatewayFactory::class);
        $this->app->bind(SecretReferenceResolver::class, EnvironmentSecretReferenceResolver::class);
        $this->app->singleton(CapabilityPortRegistry::class);
        $this->app->scoped(EndpointKeyRuntimeStore::class);
        $this->app->bind(FiscalSourceSnapshotFactory::class, DatabaseFiscalSourceSnapshotFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $contractFake = 'Tests\\Fakes\\MixedIntegrationReservationPort';
        if ($this->app->environment('testing') && class_exists($contractFake)) {
            $this->app->make(CapabilityPortRegistry::class)->register(
                'contract_fake',
                'mixed_reservations',
                'reservations.import',
                $this->app->make($contractFake),
            );
        }

        RateLimiter::for('guest-link', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('guest-link:'.$request->ip()));
        RateLimiter::for('guest-web', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('guest-web:'.$request->ip()));
        RateLimiter::for('guest-exchange', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('guest-exchange:'.$request->ip()));
        RateLimiter::for('guest-api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('guest-api:'.$request->ip()));
        RateLimiter::for('payment-request-link', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('payment-request-link:'.$request->ip()));
        RateLimiter::for('payment-webhook', fn (Request $request): Limit => Limit::perMinute(240)
            ->by('payment-webhook:'.$request->ip()));
        RateLimiter::for('integration-webhook', fn (Request $request): Limit => Limit::perMinute(240)
            ->by('integration-webhook:'.$request->ip()));
        RateLimiter::for('commercial-voucher', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('commercial-voucher:'.$request->ip()));

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
            CommercialPromotion::class,
            CostRecord::class,
            CancellationPolicy::class,
            CancellationPolicyTier::class,
            CrmActivity::class,
            DeliveryAttempt::class,
            Deposit::class,
            DepositPolicy::class,
            DocumentGenerationRequest::class,
            DocumentTemplate::class,
            ExchangeRate::class,
            FolioLine::class,
            GeneratedDocument::class,
            Guest::class,
            GuestMergeAlias::class,
            GuestPaymentEvidence::class,
            IntegrationConnection::class,
            IntegrationConnectionCapability::class,
            IntegrationDeadLetter::class,
            IntegrationEndpointKey::class,
            IntegrationEvent::class,
            IntegrationMapping::class,
            IntegrationOperation::class,
            IntegrationReconciliation::class,
            IntegrationSyncCursor::class,
            IntegrationSyncRun::class,
            IntegrationSyncRunItem::class,
            Membership::class,
            MessageTemplate::class,
            MessageTemplateVersion::class,
            OperationalTask::class,
            Opportunity::class,
            Organization::class,
            Payment::class,
            PaymentAttempt::class,
            PaymentRequest::class,
            ProviderEvent::class,
            ProviderDispute::class,
            ProviderDisputeRevision::class,
            ProviderRefund::class,
            SettlementEntry::class,
            SettlementEntryRevision::class,
            SettlementReportImport::class,
            SettlementReportRow::class,
            SettlementVarianceAction::class,
            Program::class,
            Property::class,
            RatePlan::class,
            RateRule::class,
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
            TaxRule::class,
            Voucher::class,
            VoucherRedemption::class,
            VoucherRedemptionEvent::class,
            RatePlanService::class,
            FiscalSourceSnapshot::class,
        ] as $model) {
            $model::observe(TenantAuditObserver::class);
        }
    }
}
