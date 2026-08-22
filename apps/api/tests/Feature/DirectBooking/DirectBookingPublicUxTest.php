<?php

namespace Tests\Feature\DirectBooking;

use App\View\DirectBookingPresenter;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectBookingPublicUxTest extends TestCase
{
    private const REFERENCE = '01K3A6S2V4T8N9R7W1X0Y3Z5QM';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'session.driver' => 'array',
            'direct-booking-ui.api_base_url' => 'https://contract.example.test',
            'direct-booking-ui.allow_fixture_controls' => true,
            'direct-booking-ui.contract_mock_turnstile_token' => 'contract-mock-verification',
        ]);
        Http::preventStrayRequests();
    }

    public function test_property_search_is_localized_accessible_and_exposes_only_aggregate_bookability(): void
    {
        $this->fakeContract();

        $response = $this->get('/book/rincon-grande?lang=es&arrival_date=2026-09-10&departure_date=2026-09-13&adults=2&children=0&infants=0&currency=USD');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('Buscar estadías disponibles')
            ->assertSee('Habitación lodge')
            ->assertSee('Programa ecuestre')
            ->assertSee('No disponible para estas fechas')
            ->assertDontSee('available_count')
            ->assertDontSee('resource_id')
            ->assertDontSee('room_id')
            ->assertSee('Saltar al contenido de la reserva');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_quote_review_uses_http_only_encrypted_credentials_and_never_renders_raw_tokens_or_guest_fields(): void
    {
        $this->fakeContract();
        $rawSession = (string) $this->fixture('order-begun.json')['data']['session_token'];
        $rawRecovery = (string) $this->fixture('order-begun.json')['data']['recovery_token'];

        $response = $this->post('/book/rincon-grande/quote', [
            'arrival_date' => '2026-09-10',
            'departure_date' => '2026-09-13',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'currency' => 'USD',
            'locale' => 'es-AR',
            'option_key' => '01K3A6M5T4P8V2N9R7W1X0Y3ZQ',
            'voucher_code' => 'SAFEDEMO',
            'turnstile_token' => 'contract-mock-verification',
            'begin_idempotency_key' => (string) Str::uuid(),
            'quote_idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect('/book/rincon-grande/orders/'.self::REFERENCE.'/review');
        $suffix = substr(hash('sha256', 'rincon-grande'), 0, 12);
        $response->assertCookie('inn_booking_session_'.$suffix, $rawSession)
            ->assertCookie('inn_booking_recovery_'.$suffix, $rawRecovery)
            ->assertCookie('inn_booking_order_'.$suffix, self::REFERENCE);
        $bookingCookies = array_filter($response->headers->getCookies(), fn ($cookie): bool => str_starts_with($cookie->getName(), 'inn_booking_'));
        $this->assertCount(3, $bookingCookies);
        foreach ($bookingCookies as $cookie) {
            $this->assertSame('/book/rincon-grande', $cookie->getPath());
        }
        $headers = json_encode($response->headers->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($rawSession, $headers);
        $this->assertStringNotContainsString($rawRecovery, $headers);

        $review = $this->withSession([
            'direct_booking_ui.'.hash('sha256', 'rincon-grande:'.self::REFERENCE) => [
                'quote' => $this->fixture('quote.json')['data'],
                'property' => $this->fixture('property.json')['data'],
                'search' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            ],
        ])->withCookie('inn_booking_session_'.$suffix, $rawSession)
            ->withCookie('inn_booking_recovery_'.$suffix, $rawRecovery)
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE)
            ->get('/book/rincon-grande/orders/'.self::REFERENCE.'/review');

        $review->assertOk()
            ->assertSee('Tu cotización calculada por el servidor')
            ->assertSee('USD 3.600,00')
            ->assertSee('Depósito para continuar')
            ->assertSee('Políticas y consentimiento')
            ->assertDontSee($rawSession)
            ->assertDontSee($rawRecovery)
            ->assertDontSee('SAFEDEMO');
    }

    public function test_every_frozen_state_has_an_english_and_spanish_server_rendered_screen(): void
    {
        $this->fakeContract();
        $states = array_keys($this->fixture('order-states.json'));

        foreach (['en', 'es'] as $language) {
            foreach ($states as $state) {
                $response = $this->get('/book/rincon-grande/orders/'.self::REFERENCE.'/status?lang='.$language.'&fixture_state='.$state);
                $response->assertOk()->assertSee(__("direct-booking.states.{$state}.title"));
                $response->assertDontSee('provider_id')->assertDontSee('guest@example');
            }
        }
    }

    public function test_every_contract_failure_fails_closed_with_safe_localized_copy(): void
    {
        $this->fakeContract();

        foreach (array_keys($this->fixture('errors.json')) as $error) {
            $response = $this->get('/book/rincon-grande/orders/'.self::REFERENCE.'/status?lang=en&fixture_error='.$error);
            $response->assertOk()->assertSee(__("direct-booking.errors.{$error}"));
            $response->assertDontSee('stack trace')->assertDontSee('SQLSTATE')->assertDontSee('readiness_reasons');
        }
    }

    public function test_unpublished_copy_is_escaped_and_opaque_media_references_are_not_emitted_as_browser_urls(): void
    {
        $property = $this->fixture('property.json');
        $property['data']['name'] = '<script>alert("private")</script>';
        $property['data']['summary'] = '<img src=x onerror=alert(1)>';
        Http::fake(fn () => Http::response($property, 200));

        $response = $this->get('/book/rincon-grande');

        $response->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;private&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('public-media://', false)
            ->assertSee('The lodge beside open Patagonian grassland');
    }

    public function test_analytics_rejects_non_allowlisted_fields_and_accepts_only_safe_events(): void
    {
        $this->postJson('/book/rincon-grande/analytics', [
            'event' => 'booking_viewed',
            'locale' => 'en',
            'email' => 'must-not-be-accepted@example.test',
        ])->assertUnprocessable();

        $this->postJson('/book/rincon-grande/analytics', [
            'event' => 'booking_viewed',
            'locale' => 'en',
        ])->assertNoContent();

        $this->postJson('/book/rincon-grande/analytics', [
            'event' => 'provider_payment_approved',
            'locale' => 'en',
        ])->assertUnprocessable();
    }

    public function test_money_formatting_uses_integer_minor_units_in_both_locales(): void
    {
        $this->assertSame('USD 3,600.00', DirectBookingPresenter::money(['amount_minor' => 360000, 'currency' => 'USD'], 'en'));
        $this->assertSame('USD 3.600,00', DirectBookingPresenter::money(['amount_minor' => 360000, 'currency' => 'USD'], 'es-AR'));
        $this->assertSame('-ARS 1.200,05', DirectBookingPresenter::money(['amount_minor' => -120005, 'currency' => 'ARS'], 'es-AR'));
    }

    public function test_integrated_booking_uses_the_real_api_shape_and_enables_the_hosted_checkout_path(): void
    {
        config([
            'direct-booking-ui.api_base_url' => 'http://localhost:8000/api/v1',
            'direct-booking-ui.allow_fixture_controls' => false,
            'direct-booking-ui.contract_mock_turnstile_token' => null,
        ]);
        $this->fakeIntegratedApi();
        $suffix = substr(hash('sha256', 'estancia-viento-sur'), 0, 12);

        $search = $this->get('/book/estancia-viento-sur?lang=en&arrival_date=2026-09-10&departure_date=2026-09-12&adults=2&children=0&infants=0&currency=COP');
        $search->assertOk()
            ->assertSee('Property')
            ->assertSee('COP')
            ->assertSee('Review this stay')
            ->assertDontSee('disabled="disabled"', false);
        $this->assertStringContainsString('form-action', (string) $search->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('https://*.mercadopago.com', (string) $search->headers->get('Content-Security-Policy'));

        $quoted = $this->post('/book/estancia-viento-sur/quote', [
            'arrival_date' => '2026-09-10',
            'departure_date' => '2026-09-12',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'currency' => 'COP',
            'locale' => 'en',
            'option_key' => '01M0M41SNCGJ4AHRZB7252F2W8',
            'turnstile_token' => 'bot-verification-not-required',
            'begin_idempotency_key' => (string) Str::uuid(),
            'quote_idempotency_key' => (string) Str::uuid(),
        ]);
        $quoted->assertRedirect('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/review');

        $review = $this->withCookie('inn_booking_session_'.$suffix, 'S'.str_repeat('a', 63))
            ->withCookie('inn_booking_recovery_'.$suffix, 'R'.str_repeat('b', 63))
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE)
            ->get('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/review');
        $review->assertOk()
            ->assertSee('COP 800.00')
            ->assertSee('Deposit due to continue')
            ->assertSee('Hold this stay and continue to payment')
            ->assertDontSee('disabled="disabled"', false);

        $held = $this->withCookie('inn_booking_session_'.$suffix, 'S'.str_repeat('a', 63))
            ->withCookie('inn_booking_recovery_'.$suffix, 'R'.str_repeat('b', 63))
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE)
            ->post('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/hold', [
                'expected_state_version' => 2,
                'hold_idempotency_key' => (string) Str::uuid(),
                'first_name' => 'Public',
                'last_name' => 'Guest',
                'email' => 'integrated@example.test',
                'phone' => '+573001112233',
                'consent' => [
                    'terms' => '1',
                    'privacy' => '1',
                    'cancellation' => '1',
                    'no_show' => '1',
                ],
                'turnstile_token' => 'bot-verification-not-required',
            ]);
        $held->assertRedirect('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/status');

        $status = $this->withCookie('inn_booking_session_'.$suffix, 'S'.str_repeat('a', 63))
            ->withCookie('inn_booking_recovery_'.$suffix, 'R'.str_repeat('b', 63))
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE)
            ->get('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/status');
        $status->assertOk()
            ->assertSee('Pay securely with Mercado Pago')
            ->assertSee('Continue with selected payment method')
            ->assertDontSee('name="card_number"', false)
            ->assertDontSee('name="cvv"', false)
            ->assertDontSee('name="expiry"', false);

        Http::assertSentCount(12);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/api/v1/direct-booking/properties/estancia-viento-sur'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '8096') || str_contains($request->url(), 'fixture_state'));
    }

    public function test_spanish_chrome_uses_an_english_api_locale_when_the_property_only_publishes_english(): void
    {
        config([
            'direct-booking-ui.api_base_url' => 'http://localhost:8000/api/v1',
            'direct-booking-ui.allow_fixture_controls' => false,
            'direct-booking-ui.contract_mock_turnstile_token' => null,
        ]);
        $this->fakeIntegratedApi();

        $response = $this->get('/book/estancia-viento-sur?lang=es&arrival_date=2026-09-10&departure_date=2026-09-12&adults=2&children=0&infants=0&currency=COP');

        $response->assertOk()
            ->assertSee('Buscar estadías disponibles')
            ->assertSee('name="locale" value="en"', false)
            ->assertSee('name="ui_locale" value="es-AR"', false);
    }

    public function test_held_status_keeps_the_server_quote_and_manual_instructions_are_not_fixture_copy(): void
    {
        config([
            'direct-booking-ui.api_base_url' => 'http://localhost:8000/api/v1',
            'direct-booking-ui.allow_fixture_controls' => false,
            'direct-booking-ui.contract_mock_turnstile_token' => null,
        ]);
        $this->fakeIntegratedApi();
        $suffix = substr(hash('sha256', 'estancia-viento-sur'), 0, 12);
        $flowKey = 'direct_booking_ui.'.hash('sha256', 'estancia-viento-sur:'.self::REFERENCE);

        $response = $this->withSession([
            $flowKey => [
                'quote' => $this->integratedQuote(),
                'property' => $this->integratedProperty(),
                'search' => ['adults' => 2, 'children' => 0, 'infants' => 0, 'locale' => 'en', 'ui_locale' => 'en'],
            ],
        ])->withCookie('inn_booking_session_'.$suffix, 'S'.str_repeat('a', 63))
            ->withCookie('inn_booking_recovery_'.$suffix, 'R'.str_repeat('b', 63))
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE)
            ->get('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/status');

        $response->assertOk()
            ->assertSee('COP 800.00')
            ->assertSee('Not paid')
            ->assertSee('Stay held temporarily');
        $response->assertDontSee('Contract-mock');
    }

    public function test_confirmation_renders_only_api_document_download_paths(): void
    {
        config([
            'direct-booking-ui.api_base_url' => 'http://localhost:8000/api/v1',
            'direct-booking-ui.allow_fixture_controls' => false,
            'direct-booking-ui.contract_mock_turnstile_token' => null,
        ]);
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_contains($path, '/confirmation/documents/')) {
                return Http::response('%PDF-1.7 integrated document', 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="receipt.pdf"',
                ]);
            }
            if (str_ends_with($path, '/confirmation')) {
                return Http::response([
                    'data' => [
                        'order_reference' => self::REFERENCE,
                        'confirmation_number' => 'RSV-INTEGRATED-1',
                        'state' => 'confirmed',
                        'arrival_date' => '2026-09-10',
                        'departure_date' => '2026-09-12',
                        'timezone' => 'America/Argentina/Rio_Gallegos',
                        'total' => ['amount_minor' => 80000, 'currency' => 'COP'],
                        'documents' => [[
                            'kind' => 'payment_receipt',
                            'status' => 'generated',
                            'document_reference' => str_repeat('a', 64),
                            'download_path' => '/api/v1/direct-booking/properties/estancia-viento-sur/orders/'.self::REFERENCE.'/confirmation/documents/'.str_repeat('a', 64),
                        ]],
                        'links' => ['guest_portal' => ['status' => 'invitation_required', 'entry_path' => '/guest/stay']],
                    ],
                ], 200);
            }
            if (str_ends_with($path, '/orders/'.self::REFERENCE)) {
                return Http::response(['data' => [
                    ...$this->integratedHeldStatus(),
                    'state' => 'confirmed',
                    'state_version' => 4,
                    'actions' => ['view_confirmation'],
                    'confirmation_number' => 'RSV-INTEGRATED-1',
                ]], 200);
            }

            return Http::response(['data' => $this->integratedProperty()], 200);
        });
        $suffix = substr(hash('sha256', 'estancia-viento-sur'), 0, 12);
        $flowKey = 'direct_booking_ui.'.hash('sha256', 'estancia-viento-sur:'.self::REFERENCE);

        $response = $this->withSession([
            $flowKey => [
                'quote' => $this->integratedQuote(),
                'property' => $this->integratedProperty(),
                'search' => ['adults' => 2, 'children' => 0, 'infants' => 0, 'locale' => 'en', 'ui_locale' => 'en'],
            ],
        ])->withCookie('inn_booking_session_'.$suffix, 'S'.str_repeat('a', 63))
            ->withCookie('inn_booking_recovery_'.$suffix, 'R'.str_repeat('b', 63))
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE)
            ->get('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/status');

        $response->assertOk()
            ->assertSee('RSV-INTEGRATED-1')
            ->assertSee('/api/v1/direct-booking/properties/estancia-viento-sur/orders/'.self::REFERENCE.'/confirmation/documents/'.str_repeat('a', 64), false)
            ->assertSee('Booking documents')
            ->assertSee('Payment receipt')
            ->assertDontSee('DIRECT_BOOKING_CONFIRMED_DOCUMENTS_URL')
            ->assertDontSee('/app/storage');

        $document = $this->withCookie('inn_booking_session_'.$suffix, 'S'.str_repeat('a', 63))
            ->withCookie('inn_booking_recovery_'.$suffix, 'R'.str_repeat('b', 63))
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE)
            ->get('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/documents/'.str_repeat('a', 64));
        $document->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="receipt.pdf"')
            ->assertSee('%PDF-1.7 integrated document');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/confirmation/documents/'.str_repeat('a', 64))
            && $request->hasHeader('Authorization', 'Bearer S'.str_repeat('a', 63)));
    }

    public function test_manual_transfer_screen_uses_instructions_returned_by_the_api_checkout(): void
    {
        config([
            'direct-booking-ui.api_base_url' => 'http://localhost:8000/api/v1',
            'direct-booking-ui.allow_fixture_controls' => false,
            'direct-booking-ui.contract_mock_turnstile_token' => null,
        ]);
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/checkout')) {
                return Http::response(['data' => [
                    'order_reference' => self::REFERENCE,
                    'state' => 'awaiting_manual_payment',
                    'state_version' => 4,
                    'method' => 'manual_bank_transfer',
                    'checkout_url' => null,
                    'hold_expires_at' => '2026-09-10T12:30:00Z',
                    'checkout_expires_at' => '2026-09-10T12:30:00Z',
                    'manual_payment_instructions' => [
                        'locale' => 'en',
                        'currency' => 'COP',
                        'title' => 'Bank transfer instructions',
                        'body' => 'Use the current property reference and send the exact COP deposit.',
                        'version' => 2,
                        'checksum' => str_repeat('b', 64),
                    ],
                ]], 200);
            }
            if (str_ends_with($path, '/orders/'.self::REFERENCE)) {
                return Http::response(['data' => [
                    'order_reference' => self::REFERENCE,
                    'state' => 'awaiting_manual_payment',
                    'state_version' => 4,
                    'session_expires_at' => '2026-09-10T14:00:00Z',
                    'quote_expires_at' => '2026-09-10T12:20:00Z',
                    'hold_expires_at' => '2026-09-10T12:30:00Z',
                    'checkout_expires_at' => '2026-09-10T12:30:00Z',
                    'payment_capabilities' => [['method' => 'manual_bank_transfer', 'currency' => 'COP']],
                    'actions' => ['submit_manual_evidence'],
                    'safe_failure_code' => null,
                ]], 200);
            }

            return Http::response(['data' => $this->integratedProperty()], 200);
        });
        $suffix = substr(hash('sha256', 'estancia-viento-sur'), 0, 12);
        $flowKey = 'direct_booking_ui.'.hash('sha256', 'estancia-viento-sur:'.self::REFERENCE);
        $session = [
            $flowKey => [
                'quote' => $this->integratedQuote(),
                'property' => $this->integratedProperty(),
                'search' => ['adults' => 2, 'children' => 0, 'infants' => 0, 'locale' => 'en', 'ui_locale' => 'en'],
            ],
        ];
        $request = $this->withSession($session)
            ->withCookie('inn_booking_session_'.$suffix, 'S'.str_repeat('a', 63))
            ->withCookie('inn_booking_recovery_'.$suffix, 'R'.str_repeat('b', 63))
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE);

        $request->post('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/checkout', [
            'expected_state_version' => 3,
            'method' => 'manual_bank_transfer',
            'checkout_idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/status');

        $this->withCookie('inn_booking_session_'.$suffix, 'S'.str_repeat('a', 63))
            ->withCookie('inn_booking_recovery_'.$suffix, 'R'.str_repeat('b', 63))
            ->withCookie('inn_booking_order_'.$suffix, self::REFERENCE)
            ->get('/book/estancia-viento-sur/orders/'.self::REFERENCE.'/status')
            ->assertOk()
            ->assertSee('Bank transfer instructions')
            ->assertSee('Use the current property reference and send the exact COP deposit.')
            ->assertSee('Instruction version 2')
            ->assertDontSee('Contract-mock')
            ->assertDontSee('UI fixture only');
    }

    private function fakeContract(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (isset($query['fixture_error'])) {
                $catalog = $this->fixture('errors.json');
                $manifest = $this->fixture('manifest.json');
                $error = (string) $query['fixture_error'];

                return Http::response($this->fixture($manifest['error_fixtures'][$error]), (int) $catalog[$error]['status']);
            }
            if (isset($query['fixture_state'])) {
                return Http::response($this->fixture('order-states.json')[$query['fixture_state']], 200);
            }
            if (str_ends_with($path, '/confirmation')) {
                return Http::response($this->fixture('confirmation.json'), 200);
            }
            if (str_contains($path, '/policies/')) {
                return Http::response($this->fixture('policy.json'), 200);
            }
            if (str_ends_with($path, '/availability')) {
                return Http::response($this->fixture('availability.json'), 200);
            }
            if (str_ends_with($path, '/quote')) {
                return Http::response($this->fixture('quote.json'), 200);
            }
            if (str_ends_with($path, '/hold')) {
                return Http::response($this->fixture('order-held.json'), 200);
            }
            if (str_ends_with($path, '/checkout') || str_ends_with($path, '/payments/retry')) {
                return Http::response($this->fixture('checkout.json'), 200);
            }
            if (str_ends_with($path, '/manual-payment-evidence')) {
                return Http::response($this->fixture('evidence-pending.json'), 202);
            }
            if (str_ends_with($path, '/recover')) {
                return Http::response($this->fixture('order-begun.json'), 200);
            }
            if (str_ends_with($path, '/orders')) {
                return Http::response($this->fixture('order-begun.json'), 201);
            }
            if (preg_match('#/orders/[0-9A-HJKMNP-TV-Z]{26}$#', $path)) {
                return Http::response($this->fixture('order-held.json'), 200);
            }

            $property = $this->fixture('property.json');
            if (($query['locale'] ?? null) === 'en') {
                $property['data']['locale'] = 'en';
            }

            return Http::response($property, 200);
        });
    }

    private function fakeIntegratedApi(): void
    {
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/policies/terms')
                || str_ends_with($path, '/policies/privacy')
                || str_ends_with($path, '/policies/cancellation')
                || str_ends_with($path, '/policies/no_show')
                || str_ends_with($path, '/policies/marketing_consent')) {
                return Http::response([
                    'data' => [
                        'kind' => basename($path),
                        'locale' => 'en',
                        'version' => 1,
                        'checksum' => str_repeat('a', 64),
                        'title' => 'Published policy',
                        'body' => 'Published policy content.',
                        'effective_at' => '2026-09-01T12:00:00Z',
                    ],
                ], 200);
            }
            if (str_ends_with($path, '/availability')) {
                return Http::response([
                    'data' => [
                        'arrival_date' => '2026-09-10',
                        'departure_date' => '2026-09-12',
                        'timezone' => 'America/Argentina/Rio_Gallegos',
                        'currency' => 'COP',
                        'options' => [
                            ['key' => '01M0M41SNCGJ4AHRZB7252F2W8', 'kind' => 'category', 'bookable' => true],
                        ],
                    ],
                ], 200);
            }
            if (str_ends_with($path, '/quote')) {
                return Http::response(['data' => $this->integratedQuote()], 200);
            }
            if (str_ends_with($path, '/hold')) {
                return Http::response(['data' => $this->integratedHeldStatus()], 200);
            }
            if (str_ends_with($path, '/checkout')) {
                return Http::response([
                    'data' => [
                        'order_reference' => self::REFERENCE,
                        'state' => 'payment_pending',
                        'state_version' => 4,
                        'method' => 'hosted_checkout',
                        'checkout_url' => 'https://www.mercadopago.com.co/checkout/v1/redirect?pref_id=opaque',
                        'hold_expires_at' => '2026-09-10T12:30:00Z',
                        'checkout_expires_at' => '2026-09-10T12:45:00Z',
                    ],
                ], 200);
            }
            if (str_ends_with($path, '/orders/'.self::REFERENCE)) {
                return Http::response(['data' => $this->integratedHeldStatus()], 200);
            }
            if (str_ends_with($path, '/orders')) {
                return Http::response([
                    'data' => [
                        'order_reference' => self::REFERENCE,
                        'session_token' => 'S'.str_repeat('a', 63),
                        'recovery_token' => 'R'.str_repeat('b', 63),
                        'state' => 'started',
                        'state_version' => 1,
                        'locale' => 'en',
                        'currency' => 'COP',
                        'session_expires_at' => '2026-09-10T14:00:00Z',
                        'recovery_expires_at' => '2026-09-17T12:00:00Z',
                    ],
                ], 201);
            }

            return Http::response(['data' => $this->integratedProperty()], 200);
        });
    }

    /** @return array<string, mixed> */
    private function integratedProperty(): array
    {
        return [
            'slug' => 'estancia-viento-sur',
            'name' => 'Property',
            'summary' => 'Safe public summary.',
            'locale' => 'en',
            'timezone' => 'America/Argentina/Rio_Gallegos',
            'supported_locales' => ['en'],
            'supported_currencies' => ['COP'],
            'accessible_fallback_url' => null,
            'media' => [],
            'bookables' => [[
                'key' => '01M0M41SNCGJ4AHRZB7252F2W8',
                'kind' => 'category',
                'name' => 'Cabin',
                'summary' => 'A private cabin.',
                'media' => [],
            ]],
            'optional_services' => [],
            'payment_capabilities' => [
                ['method' => 'hosted_checkout', 'currency' => 'COP'],
                ['method' => 'manual_bank_transfer', 'currency' => 'COP'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function integratedQuote(): array
    {
        return [
            'order_reference' => self::REFERENCE,
            'state' => 'quoted',
            'state_version' => 2,
            'quote_expires_at' => '2026-09-10T12:20:00Z',
            'arrival_date' => '2026-09-10',
            'departure_date' => '2026-09-12',
            'timezone' => 'America/Argentina/Rio_Gallegos',
            'total' => ['amount_minor' => 80000, 'currency' => 'COP'],
            'deposit' => ['amount_minor' => 40000, 'currency' => 'COP'],
            'lines' => [
                ['type' => 'nightly_rate', 'description' => 'Cabin · Sep 10, 2026', 'amount' => ['amount_minor' => 40000, 'currency' => 'COP']],
                ['type' => 'nightly_rate', 'description' => 'Cabin · Sep 11, 2026', 'amount' => ['amount_minor' => 40000, 'currency' => 'COP']],
            ],
            'policies' => [
                ['kind' => 'terms', 'version' => 1, 'checksum' => str_repeat('a', 64)],
                ['kind' => 'privacy', 'version' => 1, 'checksum' => str_repeat('a', 64)],
                ['kind' => 'cancellation', 'version' => 1, 'checksum' => str_repeat('a', 64)],
                ['kind' => 'no_show', 'version' => 1, 'checksum' => str_repeat('a', 64)],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function integratedHeldStatus(): array
    {
        return [
            'order_reference' => self::REFERENCE,
            'state' => 'held',
            'state_version' => 3,
            'session_expires_at' => '2026-09-10T14:00:00Z',
            'quote_expires_at' => '2026-09-10T12:20:00Z',
            'hold_expires_at' => '2026-09-10T12:30:00Z',
            'checkout_expires_at' => null,
            'payment_capabilities' => [
                ['method' => 'hosted_checkout', 'currency' => 'COP'],
                ['method' => 'manual_bank_transfer', 'currency' => 'COP'],
            ],
            'actions' => ['checkout'],
            'safe_failure_code' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path('../../contracts/direct-booking/v1/fixtures/'.$name)), true, flags: JSON_THROW_ON_ERROR);
    }
}
