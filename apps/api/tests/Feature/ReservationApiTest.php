<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Resource;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_api_calculates_total_in_minor_units_and_rejects_cross_tenant_foreign_ids(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $payload = [
            'property_id' => $property->id,
            'starts_at' => '2026-09-10T15:00:00Z',
            'ends_at' => '2026-09-12T11:00:00Z',
            'adults' => 2,
            'currency' => 'COP',
            'subtotal_minor' => 50000000,
            'tax_minor' => 9500000,
        ];

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/reservations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.total_minor', 59500000)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('audits', [
            'tenant_id' => $tenant->id,
            'event' => 'created',
            'auditable_type' => Reservation::class,
        ]);

        app(TenantContext::class)->clear();
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->set($otherTenant);
        $otherProperty = Property::factory()->create();

        $payload['property_id'] = $otherProperty->id;
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/reservations', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('property_id');
    }

    public function test_stale_reservation_updates_are_rejected_with_a_conflict(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);

        $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'If-Match' => '1'])
            ->patchJson("/api/v1/reservations/{$reservation->id}", ['notes' => 'Current version'])
            ->assertOk()
            ->assertJsonPath('data.revision', 2);

        $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'If-Match' => '1'])
            ->patchJson("/api/v1/reservations/{$reservation->id}", ['notes' => 'Stale overwrite'])
            ->assertConflict();

        $this->assertSame('Current version', $reservation->fresh()->notes);
    }

    public function test_retrying_a_command_with_the_same_idempotency_key_replays_its_response(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $payload = [
            'property_id' => $property->id,
            'starts_at' => '2026-10-10T15:00:00Z',
            'ends_at' => '2026-10-12T11:00:00Z',
            'adults' => 2,
            'currency' => 'USD',
        ];
        $headers = [
            'X-Tenant-ID' => $tenant->id,
            'Idempotency-Key' => 'reservation-retry-0001',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v1/reservations', $payload)->assertCreated();
        $second = $this->withHeaders($headers)->postJson('/api/v1/reservations', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_editing_primary_guest_keeps_the_guest_pivot_in_sync(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $previousPrimary = Guest::factory()->create();
        $newPrimary = Guest::factory()->create();
        $companion = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $previousPrimary->id,
        ]);
        foreach ([[$previousPrimary, 'primary'], [$companion, 'guest']] as [$guest, $role]) {
            ReservationGuest::query()->create([
                'reservation_id' => $reservation->id,
                'guest_id' => $guest->id,
                'role' => $role,
            ]);
        }

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/v1/reservations/{$reservation->id}", ['primary_guest_id' => $newPrimary->id])
            ->assertOk();

        $this->assertDatabaseHas('reservation_guests', [
            'reservation_id' => $reservation->id,
            'guest_id' => $newPrimary->id,
            'role' => 'primary',
        ]);
        $this->assertDatabaseHas('reservation_guests', [
            'reservation_id' => $reservation->id,
            'guest_id' => $companion->id,
            'role' => 'guest',
        ]);
        $this->assertDatabaseMissing('reservation_guests', [
            'reservation_id' => $reservation->id,
            'guest_id' => $previousPrimary->id,
        ]);
    }

    public function test_editing_stay_dates_updates_full_stay_allocations_and_preserves_contained_activity_dates(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $stayResource = Resource::factory()->create(['property_id' => $property->id]);
        $activityResource = Resource::factory()->create(['property_id' => $property->id]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Hold,
            'hold_expires_at' => now()->addHour(),
            'starts_at' => '2026-09-10T15:00:00Z',
            'ends_at' => '2026-09-14T11:00:00Z',
        ]);
        $stay = $reservation->allocations()->create([
            'resource_id' => $stayResource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);
        $activity = $reservation->allocations()->create([
            'resource_id' => $activityResource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => '2026-09-12T09:00:00Z',
            'ends_at' => '2026-09-12T12:00:00Z',
            'quantity' => 1,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/v1/reservations/{$reservation->id}", [
                'starts_at' => '2026-09-11T15:00:00Z',
                'ends_at' => '2026-09-15T11:00:00Z',
            ])->assertOk();

        $this->assertSame('2026-09-11T15:00:00+00:00', $stay->fresh()->starts_at->toIso8601String());
        $this->assertSame('2026-09-15T11:00:00+00:00', $stay->fresh()->ends_at->toIso8601String());
        $this->assertSame('2026-09-12T09:00:00+00:00', $activity->fresh()->starts_at->toIso8601String());
        $this->assertSame('2026-09-12T12:00:00+00:00', $activity->fresh()->ends_at->toIso8601String());
    }

    public function test_edit_rejects_property_changes_when_allocations_exist(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $membership->update(['property_id' => null]);
        app(TenantContext::class)->set($tenant, $membership->fresh());
        $otherProperty = Property::factory()->create();
        $resource = Resource::factory()->create(['property_id' => $property->id]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $allocation = $reservation->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/v1/reservations/{$reservation->id}", ['property_id' => $otherProperty->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('property_id');

        $this->assertSame($property->id, $reservation->fresh()->property_id);
        $this->assertDatabaseHas('allocations', [
            'id' => $allocation->id,
            'reservation_id' => $reservation->id,
            'resource_id' => $resource->id,
        ]);
    }

    public function test_edit_rejects_stay_dates_that_exclude_a_dated_allocation(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $resource = Resource::factory()->create(['property_id' => $property->id]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'starts_at' => '2026-09-10T15:00:00Z',
            'ends_at' => '2026-09-14T11:00:00Z',
        ]);
        $allocation = $reservation->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => '2026-09-11T09:00:00Z',
            'ends_at' => '2026-09-11T12:00:00Z',
            'quantity' => 1,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/v1/reservations/{$reservation->id}", [
                'starts_at' => '2026-09-12T15:00:00Z',
                'ends_at' => '2026-09-15T11:00:00Z',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertSame('2026-09-10T15:00:00+00:00', $reservation->fresh()->starts_at->toIso8601String());
        $this->assertSame('2026-09-11T09:00:00+00:00', $allocation->fresh()->starts_at->toIso8601String());
    }

    public function test_partial_date_edit_cannot_invert_the_stay_interval(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'starts_at' => '2026-09-10T15:00:00Z',
            'ends_at' => '2026-09-14T11:00:00Z',
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/v1/reservations/{$reservation->id}", [
                'starts_at' => '2026-09-15T15:00:00Z',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->assertSame('2026-09-10T15:00:00+00:00', $reservation->fresh()->starts_at->toIso8601String());
        $this->assertSame('2026-09-14T11:00:00+00:00', $reservation->fresh()->ends_at->toIso8601String());
    }
}
