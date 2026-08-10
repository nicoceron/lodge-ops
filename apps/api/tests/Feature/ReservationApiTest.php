<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Reservation;
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
}
