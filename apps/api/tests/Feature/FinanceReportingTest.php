<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\ExchangeRateService;
use App\Services\MoneyFormatter;
use App\Services\Projections\FinanceProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use NumberFormatter;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FinanceReportingTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_tenant_wide_rate_snapshots_are_unique_at_an_effective_time(): void
    {
        [$tenant] = $this->tenantEnvironment(MembershipRole::Finance);
        $effectiveAt = CarbonImmutable::parse('2026-08-01 00:00:00', $tenant->timezone);
        $service = app(ExchangeRateService::class);

        $service->snapshot('USD', 'ARS', '1000.0000000000', 'first-source', $effectiveAt);

        $this->expectException(UniqueConstraintViolationException::class);
        $service->snapshot('USD', 'ARS', '1001.0000000000', 'duplicate-time', $effectiveAt);
    }

    public function test_rate_book_prefers_a_property_snapshot_and_falls_back_to_the_tenant_snapshot(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $otherProperty = Property::factory()->create(['name' => 'Other property']);
        $hiddenProperty = Property::factory()->create(['name' => 'Hidden property']);
        $service = app(ExchangeRateService::class);
        $tenantEffectiveAt = CarbonImmutable::parse('2026-08-01 00:00:00', $tenant->timezone);
        $propertyEffectiveAt = $tenantEffectiveAt->addDay();

        $tenantRate = $service->snapshot('USD', 'ARS', '1000.0000000000', 'central-bank', $tenantEffectiveAt);
        $scopedRate = $service->snapshot('USD', 'ARS', '1200.0000000000', 'property-ledger', $propertyEffectiveAt, $property->id);
        $hiddenRate = $service->snapshot('USD', 'ARS', '1300.0000000000', 'hidden-property-ledger', $propertyEffectiveAt, $hiddenProperty->id);

        $propertyRate = $service->applicable('USD', 'ARS', $propertyEffectiveAt->addHour(), $property->id);
        $otherPropertyRate = $service->applicable('USD', 'ARS', $propertyEffectiveAt->addHour(), $otherProperty->id);

        $this->assertSame('1200.0000000000', $propertyRate?->rate);
        $this->assertSame('property-ledger', $propertyRate?->source);
        $this->assertSame('1000.0000000000', $otherPropertyRate?->rate);
        $this->assertSame($property->id, $propertyRate?->property_id);
        $visibleRateIds = ExchangeRateResource::getEloquentQuery()->pluck('id');
        $this->assertTrue($visibleRateIds->contains($tenantRate->id));
        $this->assertTrue($visibleRateIds->contains($scopedRate->id));
        $this->assertFalse($visibleRateIds->contains($hiddenRate->id));
        $this->assertDatabaseHas('exchange_rates', [
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'base_currency' => 'USD',
            'quote_currency' => 'ARS',
        ]);
    }

    public function test_finance_projection_preserves_raw_currency_totals_and_converts_each_amount_using_the_effective_rate(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $tenant->update(['currency' => 'USD']);
        $periodStart = CarbonImmutable::parse('2026-08-01 00:00:00', $tenant->timezone);
        $firstRateAt = $periodStart->subDay();
        $secondRateAt = $periodStart->addDays(4);
        $service = app(ExchangeRateService::class);
        $service->snapshot('USD', 'ARS', '1000.0000000000', 'central-bank', $firstRateAt);
        $service->snapshot('USD', 'ARS', '1200.0000000000', 'central-bank', $secondRateAt);

        $usdReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'FX-USD',
            'status' => ReservationStatus::Confirmed,
            'currency' => 'USD',
            'total_minor' => 10_000,
            'starts_at' => $periodStart->addDay()->utc(),
            'ends_at' => $periodStart->addDays(3)->utc(),
        ]);
        $arsReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'FX-ARS',
            'status' => ReservationStatus::Confirmed,
            'currency' => 'ARS',
            'total_minor' => 20_000,
            'starts_at' => $periodStart->addDays(5)->utc(),
            'ends_at' => $periodStart->addDays(7)->utc(),
        ]);
        $outsideReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'FX-OUTSIDE',
            'status' => ReservationStatus::Confirmed,
            'currency' => 'USD',
            'total_minor' => 99_000,
            'starts_at' => $periodStart->addMonths(2)->utc(),
            'ends_at' => $periodStart->addMonths(2)->addDays(2)->utc(),
        ]);
        Payment::query()->create([
            'reservation_id' => $usdReservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => 'USD',
            'amount_minor' => 2_000,
            'processed_at' => $periodStart->addDay()->utc(),
        ]);
        Payment::query()->create([
            'reservation_id' => $arsReservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => 'ARS',
            'amount_minor' => 3_000,
            'processed_at' => $periodStart->addDays(5)->utc(),
        ]);
        Payment::query()->create([
            'reservation_id' => $outsideReservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => 'USD',
            'amount_minor' => 77_000,
            'processed_at' => $periodStart->addDays(6)->utc(),
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/finance?start=2026-08-01&end=2026-08-31&display_currency=ARS');

        $response->assertOk()
            ->assertJsonPath('data.display_currency', 'ARS')
            ->assertJsonPath('data.raw_totals.USD.booked_revenue_minor', 10_000)
            ->assertJsonPath('data.raw_totals.USD.cash_collected_minor', 2_000)
            ->assertJsonPath('data.raw_totals.ARS.booked_revenue_minor', 20_000)
            ->assertJsonPath('data.raw_totals.ARS.cash_collected_minor', 3_000)
            ->assertJsonPath('data.consolidated_totals.booked_revenue_minor', 10_020_000)
            ->assertJsonPath('data.consolidated_totals.cash_collected_minor', 2_003_000)
            ->assertJsonPath('data.currency', 'ARS')
            ->assertJsonPath('data.summary.booked_revenue_minor', 10_020_000)
            ->assertJsonPath('data.summary.cash_collected_minor', 2_003_000)
            ->assertJsonPath('data.summary.source', 'consolidated')
            ->assertJsonPath('data.programs_by_currency.0.currency', 'ARS')
            ->assertJsonPath('data.channels_by_currency.0.currency', 'ARS')
            ->assertJsonPath('data.conversion.complete', true)
            ->assertJsonFragment([
                'from_currency' => 'USD',
                'to_currency' => 'ARS',
                'source' => 'central-bank',
                'direction' => 'direct',
            ]);
    }

    public function test_finance_projection_marks_consolidation_incomplete_when_an_effective_rate_is_missing(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $tenant->update(['currency' => 'USD']);
        $start = CarbonImmutable::parse('2026-08-01 00:00:00', $tenant->timezone);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'FX-MISSING-USD',
            'status' => ReservationStatus::Confirmed,
            'currency' => 'USD',
            'total_minor' => 10_000,
            'starts_at' => $start->addDay()->utc(),
            'ends_at' => $start->addDays(2)->utc(),
        ]);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'FX-MISSING-ARS',
            'status' => ReservationStatus::Confirmed,
            'currency' => 'ARS',
            'total_minor' => 20_000,
            'starts_at' => $start->addDays(3)->utc(),
            'ends_at' => $start->addDays(4)->utc(),
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/finance?start=2026-08-01&end=2026-08-31&display_currency=ARS');

        $response->assertOk()
            ->assertJsonPath('data.raw_totals.USD.booked_revenue_minor', 10_000)
            ->assertJsonPath('data.raw_totals.ARS.booked_revenue_minor', 20_000)
            ->assertJsonPath('data.conversion.complete', false)
            ->assertJsonPath('data.consolidated_totals.booked_revenue_minor', null)
            ->assertJsonFragment([
                'from_currency' => 'USD',
                'to_currency' => 'ARS',
                'status' => 'missing_rate',
            ]);
    }

    public function test_money_formatter_uses_currency_minor_units_and_the_requested_locale(): void
    {
        $formatter = app(MoneyFormatter::class);
        $usdExpected = (new NumberFormatter('en_US', NumberFormatter::CURRENCY))->formatCurrency(1234.56, 'USD');
        $arsExpected = (new NumberFormatter('es_AR', NumberFormatter::CURRENCY))->formatCurrency(1234.56, 'ARS');

        $this->assertSame($usdExpected, $formatter->formatMinor(123_456, 'USD', 'en_US'));
        $this->assertSame($arsExpected, $formatter->formatMinor(123_456, 'ARS', 'es_AR'));
        $this->assertNotSame('123456.00', $formatter->formatMinor(123_456, 'USD', 'en_US'));
    }

    public function test_finance_period_excludes_deposits_outside_the_range_and_rejects_reversed_api_dates(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => $tenant->currency,
            'starts_at' => '2026-08-10 15:00:00',
            'ends_at' => '2026-08-14 11:00:00',
        ]);
        Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'status' => DepositStatus::Due,
            'schedule_type' => 'manual',
            'currency' => $tenant->currency,
            'amount_minor' => 10_000,
            'due_at' => '2026-08-15 12:00:00',
        ]);
        Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'status' => DepositStatus::Due,
            'schedule_type' => 'balance',
            'currency' => $tenant->currency,
            'amount_minor' => 20_000,
            'due_at' => '2026-09-15 12:00:00',
        ]);

        $projection = app(FinanceProjectionService::class)->build(
            CarbonImmutable::parse('2026-08-01 00:00:00 UTC'),
            CarbonImmutable::parse('2026-09-01 00:00:00 UTC'),
            $tenant->currency,
        );

        $this->assertSame(1, $projection['deposits']['due_count']);
        $this->assertSame(10_000, $projection['deposits']['due_minor']);

        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/finance?start=2026-08-20&end=2026-08-10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');
    }

    public function test_finance_revenue_series_compares_property_scoped_bookings_and_collections_by_month(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 15:00:00 UTC');
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $tenant->update(['timezone' => 'UTC', 'currency' => 'USD']);
        $otherProperty = Property::factory()->for($tenant)->create();

        foreach ([
            ['property' => $property, 'confirmation' => 'TREND-JUL', 'starts_at' => '2026-07-03 15:00:00', 'total' => 200_000, 'collected' => 120_000],
            ['property' => $property, 'confirmation' => 'TREND-AUG', 'starts_at' => '2026-08-03 15:00:00', 'total' => 100_000, 'collected' => 40_000],
            ['property' => $otherProperty, 'confirmation' => 'TREND-HIDDEN', 'starts_at' => '2026-08-04 15:00:00', 'total' => 900_000, 'collected' => 900_000],
        ] as $row) {
            $reservation = Reservation::factory()->create([
                'property_id' => $row['property']->id,
                'confirmation_number' => $row['confirmation'],
                'status' => ReservationStatus::CheckedOut,
                'currency' => 'USD',
                'total_minor' => $row['total'],
                'starts_at' => $row['starts_at'],
                'ends_at' => CarbonImmutable::parse($row['starts_at'])->addDays(2),
            ]);
            Payment::query()->create([
                'reservation_id' => $reservation->id,
                'status' => PaymentStatus::Succeeded,
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'amount_minor' => $row['collected'],
                'processed_at' => CarbonImmutable::parse($row['starts_at'])->addDay(),
            ]);
        }

        $projection = app(FinanceProjectionService::class)->build(
            CarbonImmutable::parse('2026-08-01 00:00:00 UTC'),
            CarbonImmutable::parse('2026-09-01 00:00:00 UTC'),
            'USD',
        );
        $series = collect($projection['revenue_series'])->keyBy('label');

        $this->assertSame(200_000, $series['Jul']['booked_minor']);
        $this->assertSame(120_000, $series['Jul']['collected_minor']);
        $this->assertSame(100_000, $series['Aug']['booked_minor']);
        $this->assertSame(40_000, $series['Aug']['collected_minor']);

        CarbonImmutable::setTestNow();
    }

    public function test_finance_projection_bounds_excessive_reporting_ranges(): void
    {
        $this->tenantEnvironment(MembershipRole::Finance);

        $projection = app(FinanceProjectionService::class)->build(
            CarbonImmutable::parse('2020-01-01 00:00:00 UTC'),
            CarbonImmutable::parse('2030-01-01 00:00:00 UTC'),
            'USD',
        );

        $this->assertSame('2020-02-01T00:00:00+00:00', $projection['period']['end']);
    }

    public function test_finance_projection_reuses_a_short_lived_range_scoped_snapshot(): void
    {
        $this->tenantEnvironment(MembershipRole::Finance);
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = CarbonImmutable::parse('2026-08-01 00:00:00 UTC');
        $end = CarbonImmutable::parse('2026-09-01 00:00:00 UTC');

        $first = app(FinanceProjectionService::class)->build($start, $end, 'USD');
        $queriesAfterFirstBuild = count(DB::getQueryLog());
        $second = app(FinanceProjectionService::class)->build($start, $end, 'USD');

        $this->assertEquals($first, $second);
        $this->assertSame($queriesAfterFirstBuild, count(DB::getQueryLog()));
    }

    public function test_finance_revenue_series_uses_the_same_stay_cohort_for_bookings_and_all_collections(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $tenant->update(['currency' => 'USD', 'timezone' => 'UTC']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 15:00:00 UTC'));

        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'USD',
            'total_minor' => 100_000,
            'starts_at' => CarbonImmutable::parse('2025-01-05 15:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2025-01-08 11:00:00 UTC'),
        ]);
        foreach ([['2025-01-10', 40_000], ['2025-01-25', 60_000]] as [$processedAt, $amount]) {
            Payment::query()->create([
                'reservation_id' => $reservation->id,
                'status' => PaymentStatus::Succeeded,
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'amount_minor' => $amount,
                'processed_at' => CarbonImmutable::parse($processedAt.' 12:00:00 UTC'),
            ]);
        }

        $projection = app(FinanceProjectionService::class)->build(
            CarbonImmutable::parse('2025-01-01 00:00:00 UTC'),
            CarbonImmutable::parse('2025-01-20 00:00:00 UTC'),
            'USD',
        );

        $this->assertSame(['Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan'], array_column($projection['revenue_series'], 'label'));
        $this->assertSame(100_000, $projection['revenue_series'][6]['booked_minor']);
        $this->assertSame(100_000, $projection['revenue_series'][6]['collected_minor']);

        CarbonImmutable::setTestNow();
    }

    public function test_finance_dashboard_exposes_selectable_range_and_reporting_currency_controls(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $tenant->update(['currency' => 'USD']);
        $this->actingAs($user);

        $this->get(FinanceDashboard::getUrl(['tenant' => $tenant]))
            ->assertOk()
            ->assertSee('From')
            ->assertSee('Through')
            ->assertSee('Reporting currency')
            ->assertSee('USD')
            ->assertSee('ARS');
    }
}
