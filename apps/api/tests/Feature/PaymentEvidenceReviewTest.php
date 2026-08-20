<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentEvidenceStatus;
use App\Models\Deposit;
use App\Models\Guest;
use App\Models\GuestPaymentEvidence;
use App\Models\Reservation;
use App\Services\ReviewPaymentEvidence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class PaymentEvidenceReviewTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_approval_is_exactly_once_and_reconciles_the_selected_deposit(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $this->actingAs($user);
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'currency' => 'USD',
            'total_minor' => 50_000,
            'subtotal_minor' => 50_000,
            'tax_minor' => 0,
        ]);
        $deposit = Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'status' => DepositStatus::Due,
            'currency' => 'USD',
            'amount_minor' => 20_000,
        ]);
        $evidence = $this->evidence($reservation, $guest, 20_000);

        $first = app(ReviewPaymentEvidence::class)->approve($evidence, $deposit->id, $user->id, 'Matched August transfer');
        $second = app(ReviewPaymentEvidence::class)->approve($evidence->fresh(), $deposit->id, $user->id, 'Duplicate click');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
        $this->assertDatabaseHas('deposits', ['id' => $deposit->id, 'status' => 'paid', 'payment_id' => $first->id]);
        $this->assertDatabaseHas('guest_payment_evidence', [
            'id' => $evidence->id,
            'status' => PaymentEvidenceStatus::Approved->value,
            'payment_id' => $first->id,
        ]);
        $this->assertDatabaseCount('communications', 1);
        $this->assertDatabaseHas('communications', [
            'guest_id' => $guest->id,
            'reservation_id' => $reservation->id,
            'automation_key' => "payment-evidence:{$evidence->id}:approved",
        ]);
    }

    public function test_rejection_creates_no_payment_and_private_download_is_authorized(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Manager, authenticate: false);
        $this->actingAs($user);
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id]);
        $evidence = $this->evidence($reservation, $guest, 10_000);
        Storage::fake('local');
        Storage::disk('local')->put($evidence->storage_path, 'receipt');

        app(ReviewPaymentEvidence::class)->reject($evidence, 'Reference could not be found', $user->id);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('guest_payment_evidence', ['id' => $evidence->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('communications', [
            'guest_id' => $guest->id,
            'reservation_id' => $reservation->id,
            'automation_key' => "payment-evidence:{$evidence->id}:rejected",
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        $this->get(route('filament.admin.payment-evidence.download', ['tenant' => $tenant, 'evidence' => $evidence]))
            ->assertConflict();
    }

    public function test_sales_cannot_review_transfer_evidence(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Sales, authenticate: false);
        $this->actingAs($user);
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id]);
        $evidence = $this->evidence($reservation, $guest, 10_000);

        $this->assertFalse($user->can('review', $evidence));
        $this->assertFalse($user->can('download', $evidence));
        $this->assertDatabaseCount('payments', 0);
    }

    private function evidence(Reservation $reservation, Guest $guest, int $amount): GuestPaymentEvidence
    {
        return GuestPaymentEvidence::query()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'file_name' => 'receipt.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 7,
            'sha256' => hash('sha256', 'receipt'),
            'storage_path' => 'guest-payment-evidence/test/receipt.pdf',
            'status' => PaymentEvidenceStatus::Pending,
            'amount_minor' => $amount,
            'currency' => 'USD',
            'scan_status' => 'accepted',
            'submitted_at' => now(),
        ]);
    }
}
