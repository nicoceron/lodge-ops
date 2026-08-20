<?php

namespace Tests\Feature;

use App\Enums\PaymentOrigin;
use App\Enums\PaymentStatus;
use App\Models\Guest;
use App\Models\GuestPaymentEvidence;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FrontDeskTenderMigrationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_migration_backfills_legacy_truth_preserves_evidence_and_rolls_back_portably(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id]);
        $manual = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'origin' => PaymentOrigin::Manual,
            'method' => 'cash',
            'currency' => 'COP',
            'amount_minor' => 1_000,
            'processed_at' => now(),
        ]);
        $provider = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'origin' => PaymentOrigin::Provider,
            'method' => 'card',
            'provider' => 'mercado_pago',
            'environment' => 'sandbox',
            'provider_account' => 'migration-account',
            'provider_reference' => 'migration-provider-payment',
            'currency' => 'COP',
            'amount_minor' => 2_000,
            'processed_at' => now(),
        ]);
        $evidence = GuestPaymentEvidence::query()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'file_name' => 'historic.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 120,
            'sha256' => hash('sha256', 'historic'),
            'storage_path' => 'private/historic.pdf',
            'status' => 'approved',
            'scan_status' => 'accepted',
            'payment_id' => $manual->id,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'submitted_at' => now()->subDay(),
        ]);
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('payments', 'channel'));
        $this->assertFalse(Schema::hasTable('cash_shifts'));
        $this->assertDatabaseHas('guest_payment_evidence', ['id' => $evidence->id, 'status' => 'approved', 'reviewed_by' => $user->id]);

        $migration->up();
        $this->assertDatabaseHas('payments', ['id' => $manual->id, 'channel' => 'cash', 'entry_mode' => 'staff_recorded']);
        $this->assertDatabaseHas('payments', ['id' => $provider->id, 'channel' => 'online_checkout', 'entry_mode' => 'provider_reported']);
        $this->assertDatabaseHas('guest_payment_evidence', [
            'id' => $evidence->id,
            'storage_key' => 'private/historic.pdf',
            'original_name' => 'historic.pdf',
            'detected_mime' => 'application/pdf',
        ]);

        $migration->down();
        DB::table('payments')->where('id', $manual->id)->update(['method' => 'unclassified_legacy']);
        try {
            $migration->up();
            $this->fail('Contradictory legacy payment classification must abort with its record ID.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($manual->id, $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('payments', 'channel'));
        } finally {
            DB::table('payments')->where('id', $manual->id)->update(['method' => 'cash']);
            $migration->up();
        }
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_20_010100_create_front_desk_tender_controls.php');

        return $migration;
    }
}
