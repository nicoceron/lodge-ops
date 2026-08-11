<?php

namespace App\Filament\Pages;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Deposits\DepositResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Reservation;
use App\Services\Projections\FinanceProjectionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

class FinanceDashboard extends Page
{
    protected string $view = 'filament.pages.finance-dashboard';

    protected static ?string $title = 'Finance dashboard';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    public string $start = '';

    public string $end = '';

    public string $displayCurrency = '';

    public static function canAccess(): bool
    {
        return app(TenantContext::class)->membership()?->role?->canViewFinance() === true;
    }

    public function mount(): void
    {
        $tenant = app(TenantContext::class)->tenant();
        $now = CarbonImmutable::now($tenant->timezone);

        $this->start = $now->startOfMonth()->toDateString();
        $this->end = $now->endOfMonth()->toDateString();
        $this->displayCurrency = strtoupper($tenant->currency);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $timezone = $tenant->timezone;
        $now = CarbonImmutable::now($timezone);
        $startLocal = $this->date($this->start, $now->startOfMonth());
        $endLocal = $this->date($this->end, $now->endOfMonth());

        if ($endLocal->lessThan($startLocal) || $startLocal->diffInDays($endLocal) > 366) {
            $endLocal = $startLocal->addMonth()->subDay();
        }

        $start = $startLocal->startOfDay()->utc();
        $end = $endLocal->addDay()->startOfDay()->utc();
        $currencyOptions = array_values(array_unique([
            strtoupper($tenant->currency),
            'USD',
            'ARS',
        ]));
        $displayCurrency = in_array(strtoupper($this->displayCurrency), $currencyOptions, true)
            ? strtoupper($this->displayCurrency)
            : strtoupper($tenant->currency);
        $propertyId = $context->membership()?->property_id;
        $projection = app(FinanceProjectionService::class)->build($start, $end, $displayCurrency);
        $bookableStatuses = [
            ReservationStatus::Confirmed,
            ReservationStatus::CheckedIn,
            ReservationStatus::CheckedOut,
        ];
        $reservations = Reservation::query()
            ->with(['payments:id,reservation_id,status,currency,amount_minor,processed_at', 'property:id,name'])
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->whereIn('status', $bookableStatuses)
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->orderByDesc('starts_at')
            ->get();

        return [
            'range' => [
                'start' => $startLocal->toDateString(),
                'end' => $endLocal->toDateString(),
            ],
            'timezone' => $timezone,
            'locale' => $tenant->locale ?: app()->getLocale(),
            'currency' => strtoupper($tenant->currency),
            'displayCurrency' => $displayCurrency,
            'currencyOptions' => $currencyOptions,
            'reservations' => $reservations->take(10),
            'finance' => $projection,
            'rawTotals' => $projection['raw_totals'],
            'consolidatedTotals' => $projection['consolidated_totals'],
            'conversion' => $projection['conversion'],
        ];
    }

    private function date(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value ?: $fallback->toDateString(), app(TenantContext::class)->tenant()->timezone)->startOfDay();
        } catch (\Throwable) {
            return $fallback->startOfDay();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('payments')->label('Payments')->url(PaymentResource::getUrl()),
            Action::make('deposits')->label('Deposits')->url(DepositResource::getUrl()),
        ];
    }
}
