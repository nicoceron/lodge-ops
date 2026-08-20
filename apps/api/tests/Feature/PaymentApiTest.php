<?php

namespace Tests\Feature;

use App\Enums\PaymentOrigin;
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
            'channel' => 'external_terminal',
            'amount_minor' => 4001,
            'processor_alias' => 'test-terminal-network',
            'merchant_account_alias' => 'front-desk-merchant',
            'terminal_identifier' => 'terminal-01',
            'transaction_reference' => 'terminal-slip-123',
        ];

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/reservations/{$reservation->id}/front-desk-payments", $payload)
            ->assertCreated()
            ->assertJsonPath('data.payment.status', PaymentStatus::Succeeded->value)
            ->assertJsonPath('data.payment.origin', PaymentOrigin::Manual->value)
            ->assertJsonPath('data.payment.currency', 'USD')
            ->assertJsonPath('data.payment.amount_minor', 4001);

        $this->assertDatabaseHas('folio_lines', [
            'reservation_id' => $reservation->id,
            'amount_minor' => -4001,
            'currency' => 'USD',
        ]);
        app(TenantContext::class)->set($tenant);
        $this->assertSame(5999, app(MoneyCalculator::class)->reservationBalance($reservation));

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/reservations/{$reservation->id}/front-desk-payments", $payload)
            ->assertCreated();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
    }
}
