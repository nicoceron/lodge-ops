<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\FolioLineType;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\GuestPaymentEvidence;
use App\Models\GuestPortalAccessToken;
use App\Models\GuestPortalAcknowledgement;
use App\Models\GuestPortalDocument;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ServiceOccurrence;
use App\Models\Survey;
use App\Services\FolioService;
use App\Services\GuestPortalTokenService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class GuestPortalTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_magic_link_is_hashed_at_rest_exchanged_once_and_never_replayable(): void
    {
        [, , $guest, $reservation, , $magicToken] = $this->portalEnvironment();
        $access = GuestPortalAccessToken::query()->sole();

        $this->assertNotSame($magicToken, $access->token_hash);
        $this->assertSame(hash('sha256', $magicToken), $access->token_hash);

        $session = $this->exchange($magicToken);
        $access->refresh();

        $this->assertNotSame($session, $access->session_hash);
        $this->assertSame(hash('sha256', $session), $access->session_hash);
        $this->assertNotNull($access->exchanged_at);
        $this->postJson('/api/v1/guest-portal/exchange', ['token' => $magicToken])->assertUnauthorized();

        $this->portalGet('/api/v1/guest-portal/reservation', $session)
            ->assertOk()
            ->assertJsonPath('data.reservation.confirmation_number', $reservation->confirmation_number)
            ->assertJsonPath('data.reservation.guest.preferred_name', $guest->first_name);
    }

    public function test_invalid_expired_and_revoked_magic_links_return_the_same_safe_response(): void
    {
        [, , , , , $expired] = $this->portalEnvironment(expiresAt: now()->subMinute()->toImmutable());
        $expiredResponse = $this->postJson('/api/v1/guest-portal/exchange', ['token' => $expired]);
        $expiredResponse->assertUnauthorized();

        app(TenantContext::class)->clear();
        [, , , , , $revoked] = $this->portalEnvironment();
        GuestPortalAccessToken::query()->update(['revoked_at' => now()]);
        $revokedResponse = $this->postJson('/api/v1/guest-portal/exchange', ['token' => $revoked]);
        $revokedResponse->assertUnauthorized();

        $invalidResponse = $this->postJson('/api/v1/guest-portal/exchange', ['token' => 'short-invalid-token']);
        $invalidResponse->assertUnauthorized();

        $this->assertSame($expiredResponse->json('message'), $revokedResponse->json('message'));
        $this->assertSame($expiredResponse->json('message'), $invalidResponse->json('message'));
    }

    public function test_expired_and_revoked_portal_sessions_are_rejected(): void
    {
        [, , , , , $magicToken] = $this->portalEnvironment();
        $session = $this->exchange($magicToken);
        GuestPortalAccessToken::withoutGlobalScopes()->update(['session_expires_at' => now()->subSecond()]);
        $this->portalGet('/api/v1/guest-portal/reservation', $session)->assertUnauthorized();

        app(TenantContext::class)->clear();
        [, , , , , $secondMagic] = $this->portalEnvironment();
        $secondSession = $this->exchange($secondMagic);
        GuestPortalAccessToken::withoutGlobalScopes()->whereNotNull('session_hash')->update(['revoked_at' => now()]);
        $this->portalGet('/api/v1/guest-portal/reservation', $secondSession)->assertUnauthorized();
    }

    public function test_portal_response_is_tenant_safe_and_discloses_only_guest_necessary_data(): void
    {
        [$tenantA, $propertyA, $guestA, $reservationA, , $magicA] = $this->portalEnvironment();
        $guestA->forceFill(['document_type' => 'passport', 'document_number' => 'SECRET-PASSPORT'])->save();
        $this->addItinerary($propertyA, $reservationA);
        $sessionA = $this->exchange($magicA);

        app(TenantContext::class)->clear();
        [$tenantB, , $guestB, $reservationB] = $this->portalEnvironment();
        $guestB->forceFill(['document_number' => 'OTHER-SECRET'])->save();

        $response = $this->portalGet('/api/v1/guest-portal/reservation', $sessionA)
            ->assertOk()
            ->assertJsonPath('data.reservation.confirmation_number', $reservationA->confirmation_number)
            ->assertJsonPath('data.itinerary.0.title', 'Laguna Capri')
            ->assertJsonMissing(['confirmation_number' => $reservationB->confirmation_number]);

        $payload = $response->getContent();
        $this->assertStringNotContainsString($tenantA->id, $payload);
        $this->assertStringNotContainsString($tenantB->id, $payload);
        $this->assertStringNotContainsString($reservationA->id, $payload);
        $this->assertStringNotContainsString($reservationB->id, $payload);
        $this->assertStringNotContainsString('SECRET-PASSPORT', $payload);
        $this->assertStringNotContainsString('OTHER-SECRET', $payload);
        $this->assertStringNotContainsString('tenant_id', $payload);
        $this->assertStringNotContainsString('primary_guest_id', $payload);
    }

    public function test_guest_cannot_choose_or_cross_write_a_reservation(): void
    {
        [, $property, $guest, $reservationA, , $magicToken] = $this->portalEnvironment();
        $reservationB = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => Guest::factory()->create()->id,
        ]);
        $session = $this->exchange($magicToken);

        $this->portalJson('PUT', '/api/v1/guest-portal/pre-arrival', $session, [
            ...$this->validPreArrival(),
            'reservation_id' => $reservationB->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reservation_id');

        $this->assertDatabaseMissing('guest_portal_profiles', ['reservation_id' => $reservationB->id]);

        GuestPortalAccessToken::withoutGlobalScopes()->update(['reservation_id' => $reservationB->id]);
        $this->portalGet('/api/v1/guest-portal/reservation', $session)->assertNotFound();
        $this->assertDatabaseMissing('guest_portal_profiles', ['guest_id' => $guest->id, 'reservation_id' => $reservationA->id]);
    }

    public function test_guest_can_complete_the_persisted_portal_lifecycle_without_creating_a_fake_payment(): void
    {
        Storage::fake('local');
        [, , $guest, $reservation, $document, $magicToken] = $this->portalEnvironment(pastStay: true);
        FolioLine::query()->create([
            'reservation_id' => $reservation->id,
            'type' => FolioLineType::Charge,
            'description' => 'Patagonian Explorer',
            'quantity' => 1,
            'unit_amount_minor' => 600000,
            'amount_minor' => 600000,
            'currency' => 'USD',
            'posted_at' => now()->subWeek(),
        ]);
        $session = $this->exchange($magicToken);

        $this->portalJson('PUT', '/api/v1/guest-portal/pre-arrival', $session, $this->validPreArrival())
            ->assertOk()
            ->assertJsonPath('data.readiness.pre_arrival', true);
        $this->assertDatabaseHas('guest_portal_profiles', [
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
        ]);

        $waiver = [
            'document_slug' => $document->slug,
            'document_version' => $document->version,
            'document_hash' => $document->body_hash,
            'signature' => 'Alex Morgan',
            'accepted' => true,
        ];
        $this->portalJson('POST', '/api/v1/guest-portal/waiver', $session, [
            ...$waiver,
            'document_hash' => str_repeat('0', 64),
        ])->assertConflict();
        $this->portalJson('POST', '/api/v1/guest-portal/waiver', $session, $waiver)->assertCreated();
        $this->portalJson('POST', '/api/v1/guest-portal/waiver', $session, $waiver)->assertConflict();
        $this->assertSame(1, GuestPortalAcknowledgement::withoutGlobalScopes()->count());

        $upload = UploadedFile::fake()->image('transfer.jpg', 120, 80);
        $this->withToken($session)->post('/api/v1/guest-portal/payment-evidence', ['evidence' => $upload], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'review_pending');
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $storedEvidence = GuestPaymentEvidence::withoutGlobalScopes()->sole();
        Storage::disk('local')->assertExists($storedEvidence->storage_path);
        $this->assertStringContainsString("guest-payment-evidence/{$storedEvidence->tenant_id}/{$reservation->id}/", $storedEvidence->storage_path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($storedEvidence->storage_path)), $storedEvidence->sha256);
        $this->assertSame(Storage::disk('local')->size($storedEvidence->storage_path), $storedEvidence->size_bytes);

        $this->portalGet('/api/v1/guest-portal/folio', $session)
            ->assertOk()
            ->assertJsonPath('data.is_final', false)
            ->assertJsonPath('data.lines.0.description', 'Patagonian Explorer')
            ->assertJsonMissingPath('data.lines.0.metadata');

        $survey = ['stay_rating' => 5, 'guide_rating' => 5, 'comment' => 'Exceptional care.', 'share_with_team' => true];
        $this->portalJson('POST', '/api/v1/guest-portal/survey', $session, $survey)->assertCreated();
        $this->portalJson('POST', '/api/v1/guest-portal/survey', $session, $survey)->assertConflict();
        $this->assertSame(1, Survey::withoutGlobalScopes()->where('kind', 'post_stay')->count());
    }

    public function test_portal_payment_readiness_uses_the_final_folio_balance_including_extras(): void
    {
        [$tenant, , , $reservation, , $magicToken] = $this->portalEnvironment();
        app(TenantContext::class)->set($tenant);
        $folios = app(FolioService::class);
        $basePayment = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => $reservation->currency,
            'amount_minor' => $reservation->total_minor,
            'processed_at' => now(),
        ]);
        $folios->postPayment($basePayment, null);
        $folios->append($reservation, FolioLineType::Charge, 'Airport transfer', 1000, 5_000, null);
        $session = $this->exchange($magicToken);

        $this->portalGet('/api/v1/guest-portal/reservation', $session)
            ->assertOk()
            ->assertJsonPath('data.payment.balance_minor', 5_000)
            ->assertJsonPath('data.readiness.payment', false);

        app(TenantContext::class)->set($tenant);
        $extraPayment = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer',
            'currency' => $reservation->currency,
            'amount_minor' => 5_000,
            'processed_at' => now(),
        ]);
        $folios->postPayment($extraPayment, null);

        $this->portalGet('/api/v1/guest-portal/reservation', $session)
            ->assertOk()
            ->assertJsonPath('data.payment.balance_minor', 0)
            ->assertJsonPath('data.readiness.payment', true);
    }

    public function test_payment_evidence_rejects_disguised_and_oversized_files_without_storing_them(): void
    {
        Storage::fake('local');
        [, , , , , $magicToken] = $this->portalEnvironment();
        $session = $this->exchange($magicToken);

        $disguised = UploadedFile::fake()->createWithContent('receipt.jpg', '<?php echo "not an image";');
        $this->withToken($session)->post('/api/v1/guest-portal/payment-evidence', ['evidence' => $disguised], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unsupported evidence type.');

        $oversized = UploadedFile::fake()->create('receipt.pdf', 10 * 1024 + 1, 'application/pdf');
        $this->withToken($session)->post('/api/v1/guest-portal/payment-evidence', ['evidence' => $oversized], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('evidence');

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('guest_payment_evidence', 0);
    }

    public function test_identical_payment_evidence_retries_return_the_original_submission(): void
    {
        Storage::fake('local');
        [, , , $reservation, , $magicToken] = $this->portalEnvironment();
        $session = $this->exchange($magicToken);
        $content = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";

        foreach (['first.pdf', 'retry.pdf'] as $fileName) {
            $upload = UploadedFile::fake()->createWithContent($fileName, $content);
            $this->withToken($session)
                ->post('/api/v1/guest-portal/payment-evidence', ['evidence' => $upload], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('data.file_name', 'first.pdf');
        }

        $this->assertDatabaseCount('guest_payment_evidence', 1);
        $evidence = GuestPaymentEvidence::withoutGlobalScopes()->sole();
        $this->assertSame($reservation->id, $evidence->reservation_id);
        $this->assertSame(hash('sha256', $content), $evidence->sha256);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_feedback_opens_after_actual_early_checkout(): void
    {
        [, , , $reservation, , $magicToken] = $this->portalEnvironment();
        $reservation->update([
            'status' => ReservationStatus::CheckedOut,
            'actual_end_at' => now(),
            'ends_at' => now()->addDays(2),
        ]);
        $session = $this->exchange($magicToken);

        $this->portalGet('/api/v1/guest-portal/reservation', $session)
            ->assertOk()
            ->assertJsonPath('data.survey.available', true);
        $this->portalJson('POST', '/api/v1/guest-portal/survey', $session, [
            'stay_rating' => 5,
            'guide_rating' => 4,
            'comment' => 'The early departure was handled well.',
            'share_with_team' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('surveys', ['reservation_id' => $reservation->id, 'score' => 5]);
    }

    /** @return array{mixed, mixed, Guest, Reservation, GuestPortalDocument, string} */
    private function portalEnvironment(?CarbonImmutable $expiresAt = null, bool $pastStay = false): array
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create([
            'first_name' => 'Alex',
            'last_name' => 'Morgan',
            'email' => 'alex-'.fake()->unique()->numerify('#####').'@example.com',
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'starts_at' => $pastStay ? now()->subDays(7) : now()->addDays(2),
            'ends_at' => $pastStay ? now()->subDays(2) : now()->addDays(7),
            'currency' => 'USD',
            'total_minor' => 600000,
        ]);
        $document = GuestPortalDocument::query()->create([
            'property_id' => $property->id,
            'slug' => 'outdoor-waiver',
            'title' => 'Outdoor activity waiver',
            'version' => '3.2',
            'body' => 'I understand the inherent risks of remote outdoor activity.',
            'is_active' => true,
        ]);
        $issued = app(GuestPortalTokenService::class)->issue($reservation, $guest, $expiresAt);

        return [$tenant, $property, $guest, $reservation, $document, $issued['token']];
    }

    private function addItinerary($property, Reservation $reservation): void
    {
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Laguna Capri',
            'description' => 'A gentle first hike.',
            'default_duration_minutes' => 240,
            'capacity' => 8,
            'price_minor' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $occurrence = ServiceOccurrence::query()->create([
            'program_id' => $program->id,
            'property_id' => $property->id,
            'starts_at' => $reservation->starts_at->addDay()->setTime(8, 30),
            'ends_at' => $reservation->starts_at->addDay()->setTime(12, 30),
            'capacity' => 8,
            'meeting_point' => 'Map room',
        ]);
        $guide = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'guide')->id,
            'capacity' => 8,
        ]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'resource_id' => $guide->id,
            'service_occurrence_id' => $occurrence->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
            'quantity' => 2,
        ]);
    }

    private function validPreArrival(): array
    {
        return [
            'profile' => [
                'preferred_name' => 'Alex',
                'email' => 'alex@example.com',
                'mobile' => '+14155550186',
                'emergency_name' => 'Jamie Morgan',
                'emergency_phone' => '+14155550120',
            ],
            'travel' => [
                'arrival_method' => 'flight',
                'arrival_reference' => 'LA 896',
                'arrival_time' => now()->addDay()->toIso8601String(),
                'departure_reference' => 'LA 897',
                'departure_time' => now()->addDays(2)->toIso8601String(),
            ],
            'preferences' => [
                'dietary_style' => 'Vegetarian',
                'allergies' => 'None',
                'accessibility' => 'None',
                'medical_consent' => true,
            ],
        ];
    }

    private function exchange(string $magicToken): string
    {
        return (string) $this->postJson('/api/v1/guest-portal/exchange', ['token' => $magicToken])
            ->assertOk()
            ->json('data.access_token');
    }

    private function portalGet(string $uri, string $session): TestResponse
    {
        return $this->withToken($session)->getJson($uri);
    }

    private function portalJson(string $method, string $uri, string $session, array $data): TestResponse
    {
        return $this->withToken($session)->json($method, $uri, $data);
    }
}
