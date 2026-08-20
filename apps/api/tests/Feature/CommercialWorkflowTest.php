<?php

namespace Tests\Feature;

use App\Enums\FolioLineType;
use App\Enums\MembershipRole;
use App\Exceptions\CommercialWorkflowException;
use App\Models\Guest;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Reservation;
use App\Services\FolioService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommercialWorkflowTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_sent_proposal_snapshot_is_immutable_and_revision_converts_to_reservation(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Patagonia family program',
            'description' => 'A proposal-backed program.',
            'default_duration_minutes' => 240,
            'capacity' => 8,
            'price_minor' => 100_000,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $payload = [
            'property_id' => $property->id,
            'program_id' => $program->id,
            'primary_guest_id' => $guest->id,
            'starts_at' => now()->addMonth()->toIso8601String(),
            'ends_at' => now()->addMonth()->addDays(4)->toIso8601String(),
            'adults' => 2,
            'children' => 1,
            'currency' => 'USD',
            'title' => 'Patagonia family stay',
            'tax_minor' => 19000,
            'expires_at' => now()->addWeek()->toIso8601String(),
            'lines' => [
                ['description' => 'Suite · four nights', 'quantity_thousandths' => 4000, 'unit_amount_minor' => 25000],
                ['description' => 'Private transfer', 'quantity_thousandths' => 1000, 'unit_amount_minor' => 15000],
            ],
        ];

        $created = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/proposals', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.snapshot.subtotal_minor', 115000)
            ->assertJsonPath('data.total_minor', 134000)
            ->json('data');

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/proposals/{$created['id']}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.snapshot.guest.email', $guest->email);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/v1/proposals/{$created['id']}", ['tax_minor' => 1])
            ->assertConflict();

        app(TenantContext::class)->set($tenant);
        $sentProposal = Proposal::query()->findOrFail($created['id']);
        try {
            $sentProposal->update(['snapshot' => ['tampered' => true]]);
            $this->fail('A sent proposal snapshot was mutated directly.');
        } catch (CommercialWorkflowException) {
            $this->addToAssertionCount(1);
        }

        $revision = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/proposals/{$created['id']}/revise")
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/proposals/{$revision['id']}/send")
            ->assertOk();

        $reservation = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/proposals/{$revision['id']}/convert")
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_minor', 134000)
            ->json('data');

        $this->assertDatabaseHas('proposals', [
            'id' => $revision['id'],
            'status' => 'accepted',
            'reservation_id' => $reservation['id'],
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation['id'],
            'program_id' => $program->id,
        ]);
        $this->assertDatabaseHas('folio_lines', [
            'reservation_id' => $reservation['id'],
            'description' => 'Suite · four nights',
            'gross_amount_minor' => 100_000,
        ]);
        $this->assertDatabaseHas('folio_lines', [
            'reservation_id' => $reservation['id'],
            'description' => 'Private transfer',
            'gross_amount_minor' => 15_000,
        ]);
        $this->assertDatabaseHas('folio_lines', [
            'reservation_id' => $reservation['id'],
            'description' => 'Proposal tax',
            'gross_amount_minor' => 19_000,
        ]);
        app(TenantContext::class)->set($tenant);
        $converted = Reservation::query()->findOrFail($reservation['id']);
        $this->assertSame(134_000, app(FolioService::class)->summary($converted)['balance_minor']);
        $this->assertSame(0, app(FolioService::class)->summary($converted)['ledger_delta_minor']);
    }

    public function test_manual_payment_reconciliation_deposit_and_reversals_are_append_only(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'subtotal_minor' => 100000,
            'tax_minor' => 0,
            'total_minor' => 100000,
        ]);

        $deposit = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/deposits', [
                'reservation_id' => $reservation->id,
                'amount_minor' => 25000,
                'due_at' => now()->addDays(3)->toIso8601String(),
            ])->assertCreated()->json('data');

        $payment = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/reservations/{$reservation->id}/front-desk-payments", [
                'channel' => 'bank_transfer',
                'amount_minor' => 25000,
                'deposit_id' => $deposit['id'],
                'transaction_reference' => 'wire-2026-001',
                'note' => 'Matched against the August statement.',
            ])->assertCreated()->assertJsonPath('data.payment.status', 'succeeded')->json('data.payment');

        $this->assertSame('manual', $payment['origin']);

        $this->assertDatabaseHas('deposits', ['id' => $deposit['id'], 'status' => 'paid', 'payment_id' => $payment['id']]);
        $this->assertDatabaseHas('folio_lines', ['payment_id' => $payment['id'], 'amount_minor' => -25000]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'Bank returned the transfer'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');
        $this->assertDatabaseHas('deposits', ['id' => $deposit['id'], 'status' => 'refunded']);
        $this->assertDatabaseHas('folio_lines', ['payment_id' => $payment['id'], 'amount_minor' => 25000, 'type' => 'refund']);

        $adjustment = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/reservations/{$reservation->id}/folio-lines", [
                'type' => 'adjustment',
                'description' => 'Late checkout',
                'quantity_thousandths' => 1000,
                'unit_amount_minor' => 5000,
            ])->assertCreated()->json('data');
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/folio-lines/{$adjustment['id']}/reverse", ['reason' => 'Manager service recovery'])
            ->assertCreated();

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson("/api/v1/reservations/{$reservation->id}/folio")
            ->assertOk()
            ->assertJsonPath('summary.balance_minor', 100000);
        $this->assertDatabaseCount('folio_lines', 4);
    }

    public function test_folio_models_reject_edits_and_deletes(): void
    {
        [, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $line = app(FolioService::class)->append(
            $reservation,
            FolioLineType::Charge,
            'Airport transfer',
            1000,
            5000,
            auth()->id(),
            ['included_in_booked_total' => true],
        );
        $this->assertSame(5000 + $reservation->total_minor, app(FolioService::class)->summary($reservation)['balance_minor']);

        try {
            $line->update(['description' => 'Tampered']);
            $this->fail('An append-only folio line was edited.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $line->refresh();
        try {
            $line->delete();
            $this->fail('An append-only folio line was deleted.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_sales_cannot_access_financial_workflows(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Sales);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/deposits', [
                'reservation_id' => $reservation->id,
                'amount_minor' => 1000,
            ])->assertForbidden();
    }
}
