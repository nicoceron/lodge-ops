<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class RoleBoundaryApiTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_kitchen_cannot_query_guest_or_reservation_directories(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Kitchen);
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
        ]);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->getJson('/api/v1/guests')->assertForbidden();
        $this->withHeaders($headers)->getJson("/api/v1/guests/{$guest->id}")->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/reservations')->assertForbidden();
        $this->withHeaders($headers)->getJson("/api/v1/reservations/{$reservation->id}")->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/operations')->assertOk();
    }

    public function test_operations_can_manage_manual_payments_but_sales_cannot(): void
    {
        [$operationsTenant, $operationsProperty] = $this->tenantEnvironment(MembershipRole::Operations);
        $operationsReservation = Reservation::factory()->create([
            'property_id' => $operationsProperty->id,
            'currency' => 'USD',
        ]);
        $payload = [
            'channel' => 'bank_transfer',
            'amount_minor' => 25_000,
            'note' => 'Wire confirmation checked by the lodge.',
        ];

        $this->withHeader('X-Tenant-ID', $operationsTenant->id)->postJson("/api/v1/reservations/{$operationsReservation->id}/front-desk-payments", $payload)
            ->assertCreated()
            ->assertJsonPath('data.payment.amount_minor', 25_000);

        [$salesTenant, $salesProperty] = $this->tenantEnvironment(MembershipRole::Sales);
        $salesReservation = Reservation::factory()->create(['property_id' => $salesProperty->id]);
        $this->withHeader('X-Tenant-ID', $salesTenant->id)->postJson("/api/v1/reservations/{$salesReservation->id}/front-desk-payments", $payload)
            ->assertForbidden();
    }

    public function test_guide_calendar_only_contains_their_linked_resource_and_assignments(): void
    {
        [$tenant, $property, $guide] = $this->tenantEnvironment(MembershipRole::Guide);
        $otherGuide = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $otherGuide->id,
            'property_id' => $property->id,
            'role' => MembershipRole::Guide,
        ]);
        $ownResource = Resource::factory()->create([
            'property_id' => $property->id,
            'user_id' => $guide->id,
            'category_id' => $this->category($property, 'guide')->id,
            'name' => 'Own Guide Resource',
        ]);
        $otherResource = Resource::factory()->create([
            'property_id' => $property->id,
            'user_id' => $otherGuide->id,
            'category_id' => $this->category($property, 'guide')->id,
            'name' => 'Other Guide Resource',
        ]);
        $start = now()->addDay()->startOfHour();
        $ownGuest = Guest::factory()->create(['first_name' => 'Assigned', 'last_name' => 'Guest']);
        $otherGuest = Guest::factory()->create(['first_name' => 'Private', 'last_name' => 'Guest']);
        $ownReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $ownGuest->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(2),
        ]);
        $otherReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $otherGuest->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(2),
        ]);
        foreach ([[$ownReservation, $ownResource], [$otherReservation, $otherResource]] as [$reservation, $resource]) {
            $reservation->allocations()->create([
                'resource_id' => $resource->id,
                'status' => AllocationStatus::Confirmed,
                'starts_at' => $reservation->starts_at,
                'ends_at' => $reservation->ends_at,
                'quantity' => 1,
            ]);
        }

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson(
            '/api/v1/calendar?start='.$start->copy()->subDay()->toDateString().'&end='.$start->copy()->addDays(4)->toDateString(),
        );

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Own Guide Resource'])
            ->assertJsonMissing(['name' => 'Other Guide Resource'])
            ->assertJsonFragment(['title' => 'Assigned Guest'])
            ->assertJsonMissing(['title' => 'Private Guest']);
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson("/api/v1/reservations/{$otherReservation->id}")
            ->assertForbidden();
    }

    public function test_calendar_counts_an_availability_block_over_an_existing_allocation_as_a_hard_conflict(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $resource = Resource::factory()->create(['property_id' => $property->id, 'capacity' => 1]);
        $start = now()->addDay()->startOfHour();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(2),
        ]);
        $reservation->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);
        ResourceBlock::query()->create([
            'resource_id' => $resource->id,
            'starts_at' => $start->copy()->addHours(2),
            'ends_at' => $start->copy()->addHours(6),
            'reason' => 'Unexpected maintenance',
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/calendar?start='.$start->copy()->subDay()->toDateString().'&end='.$start->copy()->addDays(3)->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.hard_conflicts', 1);
    }
}
