<?php

namespace Tests\Feature;

use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Tenant;
use App\Services\BookingQuoteService;
use App\Services\CommitBookingQuote;
use App\Services\PaymentService;
use App\Services\ReservationService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class N2ApiIdempotencyTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    private Tenant $tenant;

    private Membership $membership;

    public function test_every_guarded_n2_api_command_replays_without_duplicate_mutation(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $this->tenant = $tenant;
        $this->membership = $membership;
        $category = $this->category($property, 'room');
        $firstRoom = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id]);
        $secondRoom = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id]);
        [$plan] = $this->ratePlan($property->id, $category->id, 10_000, 5000);
        $starts = CarbonImmutable::now()->addMonths(2)->startOfDay()->addHours(15);
        $ends = $starts->addDays(2)->subHours(4);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $quote = $this->assertPostReplay('/api/v1/booking-quotes', [
            'property_id' => $property->id,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'resource_id' => $firstRoom->id,
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $ends->toIso8601String(),
            'adults' => 2,
            'children' => 0,
        ], $headers, 'n2-booking-quote-0001');
        $this->assertDatabaseCount('booking_quotes', 1);

        $reservationData = $this->assertPostReplay('/api/v1/reservations', [
            'quote_id' => $quote['id'],
            'primary_guest_id' => Guest::factory()->create()->id,
        ], $headers, 'n2-reservation-commit-0001');
        $reservationId = $reservationData['id'];
        $this->assertDatabaseCount('reservations', 1);

        $this->assertPostReplay("/api/v1/reservations/{$reservationId}/confirm", [], $headers, 'n2-reservation-confirm-0001');
        $this->assertDatabaseCount('reservation_status_histories', 1);

        $amendedEnds = $ends->addDay();
        $this->assertPostReplay("/api/v1/reservations/{$reservationId}/amend", [
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'resource_id' => $firstRoom->id,
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $amendedEnds->toIso8601String(),
            'adults' => 2,
            'children' => 0,
        ], $headers, 'n2-reservation-amend-0001');
        $reservation = Reservation::query()->findOrFail($reservationId);
        $this->assertSame(1, $reservation->changes()->where('type', 'amendment')->count());

        $activeAllocation = $reservation->allocations()->where('status', 'confirmed')->firstOrFail();
        $this->assertPostReplay("/api/v1/reservations/{$reservationId}/reallocate", [
            'allocation_id' => $activeAllocation->id,
            'resource_id' => $secondRoom->id,
            'reason' => 'Idempotent room move',
        ], $headers, 'n2-reservation-move-0001');
        $this->assertSame(1, $reservation->changes()->where('type', 'resource_moved')->count());

        $tender = $this->assertPostReplay("/api/v1/reservations/{$reservationId}/front-desk-payments", [
            'channel' => 'external_terminal',
            'amount_minor' => $reservation->fresh()->total_minor,
            'processor_alias' => 'external-terminal',
            'merchant_account_alias' => 'front-desk',
            'terminal_identifier' => 'terminal-n2',
            'transaction_reference' => 'terminal-slip-n2-0001',
        ], $headers, 'n2-payment-record-0001');
        $payment = $tender['payment'];
        $this->assertSame('manual', $payment['origin']);
        $this->assertDatabaseCount('payments', 1);

        $this->assertPostReplay("/api/v1/payments/{$payment['id']}/reconcile", [], $headers, 'n2-payment-reconcile-0001');
        $this->assertSame(1, $reservation->folioLines()->where('payment_id', $payment['id'])->where('type', 'payment')->count());

        $this->assertPostReplay("/api/v1/reservations/{$reservationId}/cancel", [
            'reason' => 'Idempotent cancellation',
        ], $headers, 'n2-reservation-cancel-0001');
        $this->assertSame(1, $reservation->changes()->where('type', 'cancellation')->count());

        $refundAmount = intdiv($reservation->fresh()->total_minor, 2);
        $refund = $this->assertPostReplay("/api/v1/reservations/{$reservationId}/refunds", [
            'payment_id' => $payment['id'],
            'amount_minor' => $refundAmount,
            'reason' => 'Idempotent cancellation refund',
        ], $headers, 'n2-refund-request-0001');
        $this->assertSame(1, $reservation->changes()->where('type', 'refund_requested')->count());

        $this->assertPostReplay("/api/v1/reservations/{$reservationId}/refunds/{$refund['id']}/fail", [
            'reason' => 'Transient bank failure',
        ], $headers, 'n2-refund-fail-0001');
        $this->assertSame(1, $reservation->changes()->where('type', 'refund_failed')->count());

        $this->assertPostReplay("/api/v1/reservations/{$reservationId}/refunds/{$refund['id']}/complete", [
            'reference' => 'refund-reference-n2-0001',
        ], $headers, 'n2-refund-complete-0001');
        $this->assertSame(1, $reservation->changes()->where('type', 'refund_completed')->count());
        $this->assertSame(1, $reservation->folioLines()->where('type', 'refund')->count());

        [$noShow] = $this->confirmedReservation($property->id, $plan, $secondRoom, $starts->addMonths(3), $ends->addMonths(3));
        $this->assertPostReplay("/api/v1/reservations/{$noShow->id}/no-show", [
            'reason' => 'Guest did not arrive',
        ], $headers, 'n2-reservation-noshow-0001');
        $this->assertSame(1, $noShow->changes()->where('type', 'no_show')->count());

        $reversible = Reservation::factory()->create(['property_id' => $property->id, 'currency' => 'USD']);
        $reversiblePayment = app(PaymentService::class)->recordManual([
            'reservation_id' => $reversible->id,
            'method' => 'bank_transfer',
            'amount_minor' => 5_000,
        ], auth()->id(), true);
        $this->assertPostReplay("/api/v1/payments/{$reversiblePayment->id}/reverse", [
            'reason' => 'Returned transfer',
        ], $headers, 'n2-payment-reverse-0001');
        $this->assertSame(1, $reversible->folioLines()->where('payment_id', $reversiblePayment->id)->where('type', 'refund')->count());
    }

    /** @param array<string, mixed> $payload @param array<string, string> $headers @return array<string, mixed> */
    private function assertPostReplay(string $uri, array $payload, array $headers, string $key): array
    {
        $commandHeaders = [...$headers, 'Idempotency-Key' => $key];
        $first = $this->withHeaders($commandHeaders)->postJson($uri, $payload)->assertSuccessful();
        $second = $this->withHeaders($commandHeaders)->postJson($uri, $payload)
            ->assertStatus($first->getStatusCode())
            ->assertHeader('Idempotency-Replayed', 'true');

        app(TenantContext::class)->set($this->tenant, $this->membership);

        $this->assertSame($first->json(), $second->json(), $uri);

        return $first->json('data');
    }

    /** @return array{RatePlan, CancellationPolicy} */
    private function ratePlan(string $propertyId, string $categoryId, int $nightlyRate, int $retainedBasisPoints): array
    {
        $policy = CancellationPolicy::query()->create([
            'property_id' => $propertyId,
            'name' => 'N2 replay policy',
            'is_default' => false,
        ]);
        CancellationPolicyTier::query()->create([
            'cancellation_policy_id' => $policy->id,
            'days_before_arrival' => 365,
            'retained_basis_points' => $retainedBasisPoints,
        ]);
        $plan = RatePlan::query()->create([
            'property_id' => $propertyId,
            'cancellation_policy_id' => $policy->id,
            'name' => 'N2 replay rate',
            'currency' => 'USD',
            'maximum_occupancy' => 4,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $categoryId,
            'amount_minor' => $nightlyRate,
        ]);

        return [$plan, $policy];
    }

    /** @return array{Reservation} */
    private function confirmedReservation(string $propertyId, RatePlan $plan, Resource $room, mixed $startsAt, mixed $endsAt): array
    {
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $propertyId,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $room->category_id,
            'resource_id' => $room->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'adults' => 2,
            'children' => 0,
        ]);
        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);

        return [app(ReservationService::class)->confirm($reservation)];
    }
}
