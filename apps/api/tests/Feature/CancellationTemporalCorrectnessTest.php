<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Services\CancellationFeeCalculator;
use App\Services\MarkNoShow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CancellationTemporalCorrectnessTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    #[DataProvider('calendarCutoffCases')]
    public function test_cancellation_cutoffs_use_the_property_calendar_day(
        string $timezone,
        string $arrival,
        string $effectiveAt,
        int $expectedDays,
        int $expectedFee,
        string $expectedLocalDate,
    ): void {
        [, $property] = $this->tenantEnvironment();
        $property->update(['timezone' => $timezone]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'starts_at' => CarbonImmutable::parse($arrival),
            'ends_at' => CarbonImmutable::parse($arrival)->addDays(2),
            'subtotal_minor' => 10_000,
            'tax_minor' => 0,
            'total_minor' => 10_000,
            'cancellation_policy_snapshot' => [
                'tiers' => [[
                    'days_before_arrival' => 2,
                    'retained_basis_points' => 5000,
                    'minimum_fee_minor' => 0,
                ]],
            ],
        ]);

        $result = app(CancellationFeeCalculator::class)->calculate($reservation, CarbonImmutable::parse($effectiveAt));

        $this->assertSame($expectedDays, $result['days_before_arrival']);
        $this->assertSame($expectedFee, $result['fee_minor']);
        $this->assertSame($timezone, $result['property_timezone']);
        $this->assertSame($expectedLocalDate, $result['effective_local_date']);
        $this->assertSame(CarbonImmutable::parse($arrival)->setTimezone($timezone)->toDateString(), $result['arrival_local_date']);
        $this->assertSame(CarbonImmutable::parse($effectiveAt)->utc()->toIso8601String(), $result['effective_at_utc']);
    }

    /** @return iterable<string, array{string, string, string, int, int, string}> */
    public static function calendarCutoffCases(): iterable
    {
        yield 'non-DST before cutoff' => [
            'America/Bogota', '2026-08-20T20:00:00Z', '2026-08-17T18:00:00Z', 3, 0, '2026-08-17',
        ];
        yield 'non-DST at cutoff' => [
            'America/Bogota', '2026-08-20T20:00:00Z', '2026-08-18T05:00:00Z', 2, 5000, '2026-08-18',
        ];
        yield 'non-DST after cutoff' => [
            'America/Bogota', '2026-08-20T20:00:00Z', '2026-08-19T05:00:00Z', 1, 5000, '2026-08-19',
        ];
        yield 'spring DST instant immediately before local cutoff day' => [
            'America/New_York', '2026-03-10T19:00:00Z', '2026-03-08T04:59:59Z', 3, 0, '2026-03-07',
        ];
        yield 'spring DST instant at local cutoff day' => [
            'America/New_York', '2026-03-10T19:00:00Z', '2026-03-08T05:00:00Z', 2, 5000, '2026-03-08',
        ];
        yield 'fall DST cutoff keeps calendar-day distance across 25-hour day' => [
            'America/New_York', '2026-11-03T20:00:00Z', '2026-11-01T04:00:00Z', 2, 5000, '2026-11-01',
        ];
    }

    public function test_no_show_uses_the_same_property_local_policy_day_and_audits_the_basis(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01T05:30:00Z'));
        [, $property] = $this->tenantEnvironment();
        $property->update(['timezone' => 'America/New_York']);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => 'confirmed',
            'starts_at' => CarbonImmutable::parse('2026-11-01T20:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-11-03T16:00:00Z'),
            'subtotal_minor' => 10_000,
            'tax_minor' => 0,
            'total_minor' => 10_000,
            'cancellation_policy_snapshot' => [
                'tiers' => [[
                    'days_before_arrival' => 0,
                    'retained_basis_points' => 10_000,
                    'minimum_fee_minor' => 0,
                ]],
            ],
        ]);

        app(MarkNoShow::class)->handle($reservation, 'Guest did not arrive', auth()->id());

        $change = $reservation->changes()->where('type', 'no_show')->firstOrFail();
        $this->assertSame(10_000, $change->amount_minor);
        $this->assertSame(0, data_get($change->metadata, 'days_before_arrival'));
        $this->assertSame('America/New_York', data_get($change->metadata, 'property_timezone'));
        $this->assertSame('2026-11-01', data_get($change->metadata, 'effective_local_date'));
        $this->assertSame('2026-11-01', data_get($change->metadata, 'arrival_local_date'));
    }
}
