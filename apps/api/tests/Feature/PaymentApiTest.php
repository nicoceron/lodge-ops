<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Reservation;
use App\Services\MoneyCalculator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_captured_payment_posts_an_exact_folio_credit_and_is_idempotent(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'subtotal_minor' => 10000,
            'tax_minor' => 0,
            'total_minor' => 10000,
        ]);
        $payload = [
            'reservation_id' => $reservation->id,
            'method' => 'card',
            'provider' => 'test-gateway',
            'provider_reference' => 'pi_123',
            'amount_minor' => 4001,
            'captured' => true,
        ];

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/payments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', PaymentStatus::Succeeded->value)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.amount_minor', 4001);

        $this->assertDatabaseHas('folio_lines', [
            'reservation_id' => $reservation->id,
            'amount_minor' => -4001,
            'currency' => 'USD',
        ]);
        app(TenantContext::class)->set($tenant);
        $this->assertSame(5999, app(MoneyCalculator::class)->reservationBalance($reservation));

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/payments', $payload)
            ->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
    }
}
