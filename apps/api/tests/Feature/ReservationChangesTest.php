<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\DepositStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Services\AmendReservation;
use App\Services\BookingQuoteService;
use App\Services\CancelReservation;
use App\Services\CommitBookingQuote;
use App\Services\CompleteRefund;
use App\Services\FolioService;
use App\Services\PaymentService;
use App\Services\ReallocateResource;
use App\Services\RequestRefund;
use App\Services\ReservationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ReservationChangesTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_amendment_requotes_dates_and_price_without_overwriting_allocation_or_folio_history(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$reservation, $plan, $room] = $this->confirmedReservation($property->id, 10_000);
        $oldAllocation = $reservation->allocations()->where('status', AllocationStatus::Confirmed)->firstOrFail();
        $oldLineIds = $reservation->folioLines()->pluck('id')->all();
        DB::table('rate_rules')->where('id', $plan->rules()->firstOrFail()->id)->update(['amount_minor' => 12_000]);

        $amended = app(AmendReservation::class)->handle($reservation, [
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $room->category_id,
            'resource_id' => $room->id,
            'starts_at' => $reservation->starts_at->addDay(),
            'ends_at' => $reservation->ends_at->addDays(2),
            'adults' => 2,
            'children' => 0,
        ], auth()->id());

        $this->assertSame(36_000, $amended->total_minor);
        $this->assertSame(AllocationStatus::Released, $oldAllocation->fresh()->status);
        $replacement = $amended->allocations()->where('status', AllocationStatus::Confirmed)->firstOrFail();
        $this->assertNotSame($oldAllocation->id, $replacement->id);
        $this->assertSame($room->id, $replacement->resource_id);
        foreach ($oldLineIds as $lineId) {
            $this->assertDatabaseHas('folio_lines', ['id' => $lineId]);
        }
        $this->assertDatabaseHas('folio_lines', [
            'reservation_id' => $reservation->id,
            'type' => 'adjustment',
            'gross_amount_minor' => 16_000,
        ]);
        $this->assertDatabaseHas('reservation_changes', [
            'reservation_id' => $reservation->id,
            'type' => 'amendment',
            'amount_minor' => 16_000,
        ]);
        $this->assertSame(36_000, app(FolioService::class)->summary($amended)['balance_minor']);
    }

    public function test_amendment_price_increase_preserves_paid_deposit_and_reschedules_only_open_amounts(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$reservation, $plan, $room] = $this->confirmedReservation($property->id, 10_000);
        $paidDeposit = $reservation->deposits()->where('schedule_type', 'deposit_50')->firstOrFail();
        $openDeposit = $reservation->deposits()->where('schedule_type', 'balance')->firstOrFail();
        app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id,
            'method' => 'bank_transfer',
            'amount_minor' => 10_000,
            'deposit_id' => $paidDeposit->id,
        ], auth()->id(), true);
        DB::table('rate_rules')->where('id', $plan->rules()->firstOrFail()->id)->update(['amount_minor' => 12_000]);

        $amended = app(AmendReservation::class)->handle($reservation, [
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $room->category_id,
            'resource_id' => $room->id,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'adults' => 2,
            'children' => 0,
        ], auth()->id());

        $this->assertSame(24_000, $amended->total_minor);
        $this->assertSame(DepositStatus::Paid, $paidDeposit->fresh()->status);
        $this->assertSame(DepositStatus::Waived, $openDeposit->fresh()->status);
        $this->assertSame(14_000, (int) $amended->deposits()->where('status', DepositStatus::Due)->sum('amount_minor'));
        $this->assertDatabaseHas('deposits', [
            'reservation_id' => $reservation->id,
            'schedule_type' => 'revision_3_deposit',
            'amount_minor' => 2_000,
            'status' => DepositStatus::Due->value,
        ]);
        $this->assertDatabaseHas('deposits', [
            'reservation_id' => $reservation->id,
            'schedule_type' => 'revision_3_balance',
            'amount_minor' => 12_000,
            'status' => DepositStatus::Due->value,
        ]);
        $change = $amended->changes()->where('type', 'amendment')->firstOrFail();
        $this->assertSame(0, data_get($change->metadata, 'deposit_payment_effects.refund_requirement_minor'));
    }

    public function test_amendment_price_decrease_records_overpayment_credit_without_rewriting_paid_deposit(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$reservation, $plan, $room] = $this->confirmedReservation($property->id, 10_000);
        $paidDeposit = $reservation->deposits()->where('schedule_type', 'deposit_50')->firstOrFail();
        $openDeposit = $reservation->deposits()->where('schedule_type', 'balance')->firstOrFail();
        app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id,
            'method' => 'bank_transfer',
            'amount_minor' => 20_000,
            'deposit_id' => $paidDeposit->id,
        ], auth()->id(), true);
        DB::table('rate_rules')->where('id', $plan->rules()->firstOrFail()->id)->update(['amount_minor' => 4_000]);

        $amended = app(AmendReservation::class)->handle($reservation, [
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $room->category_id,
            'resource_id' => $room->id,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'adults' => 2,
            'children' => 0,
        ], auth()->id());

        $this->assertSame(8_000, $amended->total_minor);
        $this->assertSame(DepositStatus::Paid, $paidDeposit->fresh()->status);
        $this->assertSame(DepositStatus::Waived, $openDeposit->fresh()->status);
        $this->assertSame(0, $amended->deposits()->where('status', DepositStatus::Due)->count());
        $this->assertSame(-12_000, app(FolioService::class)->summary($amended)['balance_minor']);
        $change = $amended->changes()->where('type', 'amendment')->firstOrFail();
        $this->assertSame(12_000, data_get($change->metadata, 'deposit_payment_effects.refund_requirement_minor'));
    }

    public function test_amendment_that_would_drop_a_retained_activity_rolls_back_reservation_mutations(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$reservation, $plan, $room] = $this->confirmedReservation($property->id, 10_000);
        $guideCategory = $this->category($property, 'guide');
        $guide = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $guideCategory->id]);
        $activity = $reservation->allocations()->create([
            'requested_category_id' => $guideCategory->id,
            'resource_id' => $guide->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $reservation->starts_at->addDay()->addHours(2),
            'ends_at' => $reservation->starts_at->addDay()->addHours(5),
            'quantity' => 1,
        ]);
        $stay = $reservation->allocations()->where('requested_category_id', $room->category_id)->firstOrFail();
        $folioCount = $reservation->folioLines()->count();

        try {
            app(AmendReservation::class)->handle($reservation, [
                'rate_plan_id' => $plan->id,
                'resource_category_id' => $room->category_id,
                'resource_id' => $room->id,
                'starts_at' => $reservation->starts_at,
                'ends_at' => $reservation->starts_at->addDay(),
                'adults' => 2,
                'children' => 0,
            ], auth()->id());
            $this->fail('An amendment may not silently drop a retained activity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }

        $this->assertSame(AllocationStatus::Confirmed, $stay->fresh()->status);
        $this->assertSame(AllocationStatus::Confirmed, $activity->fresh()->status);
        $this->assertSame(20_000, $reservation->fresh()->total_minor);
        $this->assertSame($folioCount, $reservation->folioLines()->count());
        $this->assertSame(0, $reservation->changes()->where('type', 'amendment')->count());
    }

    public function test_resource_move_and_swap_are_conflict_checked_and_append_history(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$first, , $firstRoom] = $this->confirmedReservation($property->id, 10_000);
        $category = $firstRoom->category;
        $secondRoom = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
        ]);
        $firstAllocation = $first->allocations()->where('status', AllocationStatus::Confirmed)->firstOrFail();

        $moved = app(ReallocateResource::class)->handle($first, $firstAllocation, $secondRoom, auth()->id(), reason: 'Guest preference');
        $movedAllocation = $moved->allocations()->where('status', AllocationStatus::Confirmed)->firstOrFail();
        $this->assertSame($secondRoom->id, $movedAllocation->resource_id);
        $this->assertSame(AllocationStatus::Released, $firstAllocation->fresh()->status);
        $this->assertDatabaseHas('reservation_changes', ['reservation_id' => $first->id, 'type' => 'resource_moved']);

        [$second] = $this->confirmedReservation($property->id, 10_000, $firstRoom, $first->starts_at, $first->ends_at);
        $secondAllocation = $second->allocations()->where('status', AllocationStatus::Confirmed)->firstOrFail();
        $swapped = app(ReallocateResource::class)->handle($moved, $movedAllocation, $firstRoom, auth()->id(), $secondAllocation, 'Operational swap');
        $this->assertSame($firstRoom->id, $swapped->allocations()->where('status', AllocationStatus::Confirmed)->value('resource_id'));
        $this->assertSame($secondRoom->id, $second->fresh()->allocations()->where('status', AllocationStatus::Confirmed)->value('resource_id'));
        $this->assertSame(2, $first->changes()->whereIn('type', ['resource_moved', 'resource_swapped'])->count());
        $this->assertSame(1, $second->changes()->where('type', 'resource_swapped')->count());
    }

    public function test_conflicting_room_move_rolls_back_without_releasing_the_original_assignment(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$first, , $firstRoom] = $this->confirmedReservation($property->id, 10_000);
        $occupied = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $firstRoom->category_id]);
        $this->confirmedReservation($property->id, 10_000, $occupied, $first->starts_at, $first->ends_at);
        $allocation = $first->allocations()->where('status', AllocationStatus::Confirmed)->firstOrFail();

        try {
            app(ReallocateResource::class)->handle($first, $allocation, $occupied, auth()->id());
            $this->fail('Expected the occupied room move to fail.');
        } catch (\Throwable) {
            $this->assertSame(AllocationStatus::Confirmed, $allocation->fresh()->status);
            $this->assertSame($firstRoom->id, $allocation->fresh()->resource_id);
            $this->assertDatabaseMissing('reservation_changes', ['reservation_id' => $first->id, 'type' => 'resource_moved']);
        }
    }

    public function test_checked_in_move_and_swap_dirty_every_vacated_room(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$first, , $firstRoom] = $this->confirmedReservation($property->id, 10_000);
        $secondRoom = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $firstRoom->category_id]);
        [$second] = $this->confirmedReservation($property->id, 10_000, $secondRoom, $first->starts_at, $first->ends_at);
        $first = app(ReservationService::class)->transition($first, ReservationStatus::CheckedIn);
        $second = app(ReservationService::class)->transition($second, ReservationStatus::CheckedIn);
        $firstAllocation = $first->allocations()->where('status', AllocationStatus::Confirmed)->firstOrFail();
        $secondAllocation = $second->allocations()->where('status', AllocationStatus::Confirmed)->firstOrFail();

        app(ReallocateResource::class)->handle(
            $first,
            $firstAllocation,
            $secondRoom,
            auth()->id(),
            $secondAllocation,
            'In-house operational swap',
        );

        $this->assertSame(HousekeepingStatus::Dirty, $firstRoom->fresh()->housekeeping_status);
        $this->assertSame(HousekeepingStatus::Dirty, $secondRoom->fresh()->housekeeping_status);
        $this->assertSame(1, $first->changes()->where('type', 'resource_swapped')->count());
        $this->assertSame(1, $second->changes()->where('type', 'resource_swapped')->count());
    }

    public function test_cancellation_fee_and_partial_refund_are_append_only_exact_once_and_retryable(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$reservation] = $this->confirmedReservation($property->id, 10_000, cancellationBasisPoints: 5000);
        $payment = app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id,
            'method' => 'bank_transfer',
            'provider' => 'internal',
            'provider_reference' => 'transfer-n2-1',
            'amount_minor' => 20_000,
        ], auth()->id(), true);
        $this->assertSame(0, app(FolioService::class)->summary($reservation)['balance_minor']);

        $cancelled = app(CancelReservation::class)->handle($reservation, 'Guest changed plans', auth()->id());
        $this->assertSame(ReservationStatus::Cancelled, $cancelled->status);
        $this->assertSame(-10_000, app(FolioService::class)->summary($cancelled)['balance_minor']);
        $this->assertDatabaseHas('reservation_changes', [
            'reservation_id' => $reservation->id,
            'type' => 'cancellation',
            'amount_minor' => 10_000,
        ]);

        $request = app(RequestRefund::class)->handle($cancelled, $payment, 10_000, 'Refund after cancellation fee', auth()->id());
        $failed = app(CompleteRefund::class)->fail($request, 'Bank transfer temporarily unavailable', auth()->id());
        $this->assertSame('failed', $failed->status);
        $completed = app(CompleteRefund::class)->handle($request, 'refund-transfer-1', auth()->id());
        $duplicate = app(CompleteRefund::class)->handle($request, 'refund-transfer-1', auth()->id());

        $this->assertSame($completed->id, $duplicate->id);
        $this->assertSame(1, $request->events()->where('type', 'refund_completed')->count());
        $this->assertSame(1, $reservation->folioLines()->where('type', 'refund')->count());
        $this->assertSame(0, app(FolioService::class)->summary($cancelled)['balance_minor']);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);

        try {
            app(PaymentService::class)->reverse($payment, 'Legacy full reversal after partial refund', auth()->id());
            $this->fail('A payment with a completed partial refund must not be fully reversed.');
        } catch (CommercialWorkflowException $exception) {
            $this->assertStringContainsString('open or completed refund', $exception->getMessage());
        }
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(0, app(FolioService::class)->summary($cancelled)['balance_minor']);
    }

    public function test_open_refund_request_blocks_legacy_full_payment_reversal_without_financial_mutation(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$reservation] = $this->confirmedReservation($property->id, 10_000, cancellationBasisPoints: 5000);
        $payment = app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id,
            'method' => 'bank_transfer',
            'amount_minor' => 20_000,
        ], auth()->id(), true);
        $cancelled = app(CancelReservation::class)->handle($reservation, 'Guest changed plans', auth()->id());
        app(RequestRefund::class)->handle($cancelled, $payment, 5_000, 'First partial refund', auth()->id());
        $folioCount = $reservation->folioLines()->count();
        $balance = app(FolioService::class)->summary($cancelled)['balance_minor'];

        try {
            app(PaymentService::class)->reverse($payment, 'Legacy reversal collision', auth()->id());
            $this->fail('An open refund request must block a full payment reversal.');
        } catch (CommercialWorkflowException $exception) {
            $this->assertStringContainsString('dedicated correction command', $exception->getMessage());
        }

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame($folioCount, $reservation->folioLines()->count());
        $this->assertSame($balance, app(FolioService::class)->summary($cancelled)['balance_minor']);
    }

    public function test_refund_cannot_exceed_guest_credit(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$reservation] = $this->confirmedReservation($property->id, 10_000);
        $payment = app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id,
            'method' => 'bank_transfer',
            'amount_minor' => 20_000,
        ], auth()->id(), true);

        $this->expectException(ValidationException::class);
        app(RequestRefund::class)->handle($reservation, $payment, 1, 'No credit exists', auth()->id());
    }

    public function test_room_move_and_refund_role_boundaries_are_explicit(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        [$reservation] = $this->confirmedReservation($property->id, 10_000);

        $expectations = [
            MembershipRole::Sales->value => [true, false, false],
            MembershipRole::Operations->value => [true, true, false],
            MembershipRole::Finance->value => [false, true, true],
        ];

        foreach ($expectations as $role => [$mayMove, $mayRequestRefund, $mayCompleteRefund]) {
            $user = User::factory()->create();
            $membership = Membership::factory()->create([
                'user_id' => $user->id,
                'property_id' => $property->id,
                'role' => MembershipRole::from($role),
            ]);
            app(TenantContext::class)->set($tenant, $membership);

            $this->assertSame($mayMove, $user->can('reallocate', $reservation), "{$role} reallocate boundary");
            $this->assertSame($mayRequestRefund, $user->can('requestRefund', $reservation), "{$role} refund request boundary");
            $this->assertSame($mayCompleteRefund, $user->can('completeRefund', $reservation), "{$role} refund completion boundary");
        }
    }

    /** @return array{Reservation, RatePlan, resource} */
    private function confirmedReservation(
        string $propertyId,
        int $nightlyRate,
        ?Resource $room = null,
        mixed $startsAt = null,
        mixed $endsAt = null,
        int $cancellationBasisPoints = 0,
    ): array {
        $property = Property::query()->findOrFail($propertyId);
        $category = $room?->category ?? $this->category($property, 'room');
        $room ??= Resource::factory()->create([
            'property_id' => $propertyId,
            'category_id' => $category->id,
        ]);
        $policy = CancellationPolicy::query()->create([
            'property_id' => $propertyId,
            'name' => 'Policy '.fake()->unique()->word(),
            'is_default' => false,
        ]);
        CancellationPolicyTier::query()->create([
            'cancellation_policy_id' => $policy->id,
            'days_before_arrival' => 365,
            'retained_basis_points' => $cancellationBasisPoints,
        ]);
        $plan = RatePlan::query()->create([
            'property_id' => $propertyId,
            'cancellation_policy_id' => $policy->id,
            'name' => 'Rate '.fake()->unique()->word(),
            'currency' => 'USD',
            'maximum_occupancy' => 4,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'amount_minor' => $nightlyRate,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $starts = $startsAt ?? now()->addMonth()->startOfDay()->addHours(15);
        $ends = $endsAt ?? $starts->copy()->addDays(2);
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $propertyId,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'resource_id' => $room->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'adults' => 2,
            'children' => 0,
        ]);
        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);
        $reservation = app(ReservationService::class)->confirm($reservation);

        return [$reservation, $plan, $room];
    }
}
