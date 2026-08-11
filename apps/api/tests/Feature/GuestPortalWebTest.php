<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\GuestPortalDocument;
use App\Models\Reservation;
use App\Services\GuestPortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class GuestPortalWebTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_magic_link_opens_the_laravel_guest_portal_without_exposing_the_session_token(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create([
            'first_name' => 'Web',
            'last_name' => 'Guest',
            'email' => 'web-guest@example.com',
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'confirmation_number' => 'RSV-LARAVEL-GUEST',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(5),
        ]);
        GuestPortalDocument::query()->create([
            'property_id' => $property->id,
            'slug' => 'guest-terms',
            'title' => 'Guest terms',
            'version' => '1.0',
            'body' => 'Please review these guest terms.',
            'is_active' => true,
        ]);
        $magicToken = app(GuestPortalTokenService::class)->issue($reservation, $guest)['token'];

        $this->get('/guest/access/'.$magicToken)
            ->assertRedirect('/guest/stay')
            ->assertSessionHas('guest_portal_session_token');

        $this->get('/guest/stay')
            ->assertOk()
            ->assertSee('Welcome, Web')
            ->assertSee('RSV-LARAVEL-GUEST')
            ->assertDontSee($magicToken);

        $this->get('/guest/stay/pre-arrival')->assertOk()->assertSee('Pre-arrival details');
        $this->get('/guest/stay/documents')->assertOk()->assertSee('Guest terms');
        $this->get('/guest/stay/payments')->assertOk()->assertSee('Payment');
        $this->get('/guest/stay/folio')->assertOk()->assertSee('Folio');
        $this->get('/guest/stay/survey')->assertOk()->assertSee('Feedback');

        $this->get('/guest/access/'.$magicToken)->assertRedirect('/guest/unavailable');
    }

    public function test_laravel_guest_forms_reuse_the_hardened_portal_workflows(): void
    {
        Storage::fake('local');
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create(['email' => 'forms@example.com']);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(5),
        ]);
        $document = GuestPortalDocument::query()->create([
            'property_id' => $property->id,
            'slug' => 'web-waiver',
            'title' => 'Web waiver',
            'version' => '2.0',
            'body' => 'I accept the lodge conditions.',
            'is_active' => true,
        ]);
        $magicToken = app(GuestPortalTokenService::class)->issue($reservation, $guest)['token'];
        $this->get('/guest/access/'.$magicToken)->assertRedirect('/guest/stay');

        $this->put('/guest/stay/pre-arrival', [
            'profile' => [
                'preferred_name' => 'Forms Guest',
                'email' => 'forms@example.com',
                'mobile' => '+15550001111',
                'emergency_name' => 'Emergency Person',
                'emergency_phone' => '+15550002222',
            ],
            'travel' => [
                'arrival_method' => 'car',
                'arrival_reference' => 'Rental car',
                'arrival_time' => now()->addDay()->toIso8601String(),
                'departure_reference' => 'Rental car',
                'departure_time' => now()->addDays(2)->toIso8601String(),
            ],
            'preferences' => [
                'dietary_style' => 'Vegetarian',
                'allergies' => 'None',
                'accessibility' => 'None',
                'medical_consent' => '1',
            ],
        ])->assertRedirect('/guest/stay/pre-arrival');
        $this->assertDatabaseHas('guest_portal_profiles', [
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
        ]);

        $this->post('/guest/stay/documents', [
            'document_slug' => $document->slug,
            'document_version' => $document->version,
            'document_hash' => $document->body_hash,
            'signature' => 'Forms Guest',
            'accepted' => '1',
        ])->assertRedirect('/guest/stay/documents');
        $this->assertDatabaseHas('guest_portal_acknowledgements', [
            'reservation_id' => $reservation->id,
            'document_id' => $document->id,
        ]);

        $this->post('/guest/stay/payments', [
            'evidence' => UploadedFile::fake()->image('transfer.png', 120, 80),
        ])->assertRedirect('/guest/stay/payments');
        $this->assertDatabaseHas('guest_payment_evidence', [
            'reservation_id' => $reservation->id,
            'status' => 'review_pending',
        ]);
    }

    public function test_post_stay_feedback_submits_from_the_laravel_guest_portal(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subDay(),
        ]);
        $magicToken = app(GuestPortalTokenService::class)->issue($reservation, $guest)['token'];
        $this->get('/guest/access/'.$magicToken)->assertRedirect('/guest/stay');

        $this->post('/guest/stay/survey', [
            'stay_rating' => 5,
            'guide_rating' => 4,
            'comment' => 'Excellent stay.',
            'share_with_team' => '1',
        ])->assertRedirect('/guest/stay/survey');

        $this->assertDatabaseHas('surveys', [
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'kind' => 'post_stay',
            'score' => 5,
        ]);
    }
}
