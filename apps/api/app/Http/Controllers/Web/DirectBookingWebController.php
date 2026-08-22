<?php

namespace App\Http\Controllers\Web;

use App\Http\Clients\DirectBookingUiClient;
use App\Http\Controllers\Controller;
use App\Services\DirectBooking\DirectBookingPrivacy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Throwable;

final class DirectBookingWebController extends Controller
{
    private const ORDER_REFERENCE_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /** @var list<string> */
    private const POLICY_KINDS = ['terms', 'privacy', 'cancellation', 'no_show', 'marketing_consent'];

    public function __construct(private readonly DirectBookingUiClient $client) {}

    public function show(Request $request, string $propertySlug): View
    {
        $requestedLocale = $this->requestedLocale($request);
        $propertyResponse = $this->propertyResponse($request, $propertySlug, $requestedLocale);
        if (! $propertyResponse?->successful()) {
            return $this->unavailableView($request, $propertySlug, $propertyResponse);
        }

        $property = (array) $propertyResponse->json('data', []);
        $locale = $this->activateLocale($requestedLocale);
        $apiLocale = (string) ($property['locale'] ?? 'en');
        $searchAttempted = $request->hasAny(['arrival_date', 'departure_date', 'adults', 'children', 'infants']);
        $availability = null;
        $errors = Validator::make([], [])->errors();
        $programKey = $request->query('program_key');
        $programKey = is_string($programKey) && $programKey !== '' ? $programKey : null;
        $search = [
            'arrival_date' => (string) $request->query('arrival_date', ''),
            'departure_date' => (string) $request->query('departure_date', ''),
            'adults' => (int) $request->integer('adults', 2),
            'children' => (int) $request->integer('children', 0),
            'infants' => (int) $request->integer('infants', 0),
            'currency' => (string) $request->query('currency', $property['supported_currencies'][0] ?? 'USD'),
            'locale' => $apiLocale,
            'ui_locale' => $locale,
            'program_key' => $programKey,
        ];

        if ($searchAttempted) {
            $validator = Validator::make($search, $this->searchRules($property));
            if ($validator->fails()) {
                $errors = $validator->errors();
            } else {
                $availabilityResponse = $this->call(fn () => $this->client->availability($request, $propertySlug, $this->availabilityFacts($search)));
                if ($availabilityResponse?->successful()) {
                    $availability = (array) $availabilityResponse->json('data', []);
                } elseif ($this->errorCode($availabilityResponse) === 'unavailable') {
                    $availability = [
                        'arrival_date' => $search['arrival_date'],
                        'departure_date' => $search['departure_date'],
                        'timezone' => $property['timezone'] ?? 'UTC',
                        'currency' => $search['currency'],
                        'options' => [],
                    ];
                } else {
                    $errors->add('booking', $this->errorMessage($availabilityResponse));
                }
            }
        }

        return view('direct-booking.property', [
            'property' => $property,
            'propertySlug' => $propertySlug,
            'locale' => $locale,
            'search' => $search,
            'searchAttempted' => $searchAttempted,
            'availability' => $availability,
            'bookingErrors' => $errors,
            'attribution' => $this->attribution($request),
            'turnstile' => $this->turnstile('direct_booking_begin'),
        ]);
    }

    public function quote(Request $request, string $propertySlug): RedirectResponse
    {
        $uiLocale = $this->requestedLocale($request);
        $propertyResponse = $this->propertyResponse($request, $propertySlug, (string) $request->input('locale', 'en'));
        if (! $propertyResponse?->successful()) {
            return $this->landingError($propertySlug, $this->errorCode($propertyResponse));
        }
        $property = (array) $propertyResponse->json('data', []);
        $validator = Validator::make($request->all(), $this->quoteRules($property));
        if ($validator->fails()) {
            return $this->landingError($propertySlug, 'validation_error', $request->only([...array_keys($this->searchRules($property)), 'ui_locale']));
        }
        $facts = $validator->validated();
        $selected = collect((array) ($property['bookables'] ?? []))->firstWhere('key', $facts['option_key']);
        if (! is_array($selected) || ! in_array($selected['kind'] ?? null, ['category', 'program'], true)) {
            return $this->landingError($propertySlug, 'validation_error', $facts);
        }
        $facts['option_kind'] = $selected['kind'];
        $apiLocale = (string) ($property['locale'] ?? $facts['locale']);
        $locale = $this->activateLocale($uiLocale);
        $beginResponse = $this->call(fn () => $this->client->begin($request, $propertySlug, [
            'locale' => $apiLocale,
            'currency' => $facts['currency'],
            'turnstile_token' => $facts['turnstile_token'],
            'turnstile_action' => 'direct_booking_begin',
            'attribution' => $this->attribution($request, (array) ($facts['attribution'] ?? [])),
        ], $facts['begin_idempotency_key']));
        if (! $beginResponse?->successful()) {
            return $this->landingError($propertySlug, $this->errorCode($beginResponse), $facts);
        }

        $begun = (array) $beginResponse->json('data', []);
        $reference = (string) ($begun['order_reference'] ?? '');
        $sessionToken = (string) ($begun['session_token'] ?? '');
        $recoveryToken = (string) ($begun['recovery_token'] ?? '');
        if (! preg_match(self::ORDER_REFERENCE_PATTERN, $reference) || ! $this->validToken($sessionToken) || ! $this->validToken($recoveryToken)) {
            return $this->landingError($propertySlug, 'booking_unavailable', $facts);
        }

        $quoteFacts = $this->availabilityFacts($facts) + [
            'expected_state_version' => (int) ($begun['state_version'] ?? 1),
            'optional_service_keys' => array_values((array) ($facts['optional_service_keys'] ?? [])),
            'voucher_code' => filled($facts['voucher_code'] ?? null) ? $facts['voucher_code'] : null,
        ];
        if ($facts['option_kind'] === 'category') {
            $quoteFacts['category_key'] = $facts['option_key'];
            $quoteFacts['program_key'] = null;
        } else {
            $quoteFacts['category_key'] = null;
            $quoteFacts['program_key'] = $facts['option_key'];
        }
        $quoteFacts['locale'] = $apiLocale;
        $quoteResponse = $this->call(fn () => $this->client->quote(
            $request,
            $propertySlug,
            $reference,
            $sessionToken,
            $quoteFacts,
            $facts['quote_idempotency_key'],
        ));
        if (! $quoteResponse?->successful()) {
            return $this->landingError($propertySlug, $this->errorCode($quoteResponse), $facts);
        }

        $quote = (array) $quoteResponse->json('data', []);
        $request->session()->put($this->flowKey($propertySlug, $reference), [
            'quote' => $quote,
            'property' => $property,
            'search' => Arr::only($facts, ['arrival_date', 'departure_date', 'adults', 'children', 'infants', 'currency', 'option_key', 'option_kind']) + [
                'locale' => $apiLocale,
                'api_locale' => $apiLocale,
                'ui_locale' => $locale,
            ],
        ]);
        $redirect = redirect()->route('direct-booking.review', [$propertySlug, $reference]);

        return $this->withCredentials($redirect, $request, $propertySlug, $reference, $sessionToken, $recoveryToken);
    }

    public function review(Request $request, string $propertySlug, string $orderReference): View
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference);
        $flow = (array) $request->session()->get($this->flowKey($propertySlug, $orderReference), []);
        if ($credentials === null || ! isset($flow['quote'], $flow['property'])) {
            return $this->unavailableView($request, $propertySlug);
        }
        $property = (array) $flow['property'];
        $apiLocale = (string) ($flow['search']['api_locale'] ?? $property['locale'] ?? 'en');
        $locale = $this->flowLocale($request, $flow, $apiLocale);
        $quote = (array) $flow['quote'];
        [$policies, $policiesReady] = $this->loadPolicies($request, $propertySlug, $quote, $apiLocale);

        return view('direct-booking.review', [
            'property' => $property,
            'propertySlug' => $propertySlug,
            'orderReference' => $orderReference,
            'quote' => $quote,
            'search' => (array) ($flow['search'] ?? []),
            'policies' => $policies,
            'policiesReady' => $policiesReady,
            'locale' => $locale,
            'turnstile' => $this->turnstile('direct_booking_hold'),
        ]);
    }

    public function hold(Request $request, string $propertySlug, string $orderReference): RedirectResponse|View
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference);
        $flow = (array) $request->session()->get($this->flowKey($propertySlug, $orderReference), []);
        if ($credentials === null || ! isset($flow['quote'], $flow['property'])) {
            return $this->unavailableView($request, $propertySlug);
        }
        $quote = (array) $flow['quote'];
        $rules = [
            'expected_state_version' => ['required', 'integer', 'min:1'],
            'hold_idempotency_key' => ['required', 'uuid'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'consent.terms' => ['accepted'],
            'consent.privacy' => ['accepted'],
            'consent.cancellation' => ['accepted'],
            'consent.no_show' => ['accepted'],
            'consent.marketing_consent' => ['nullable', 'boolean'],
            'turnstile_token' => ['required', 'string', 'max:2048'],
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->reviewWithErrors($request, $propertySlug, $orderReference, $validator->errors()->toArray());
        }
        $facts = $validator->validated();
        [, $policiesReady] = $this->loadPolicies(
            $request,
            $propertySlug,
            $quote,
            (string) ($flow['search']['api_locale'] ?? $flow['property']['locale'] ?? 'en'),
        );
        if (! $policiesReady) {
            return $this->reviewWithErrors($request, $propertySlug, $orderReference, ['booking' => [__('direct-booking.errors.policy_missing')]]);
        }
        $snapshots = collect((array) ($quote['policies'] ?? []))->keyBy('kind');
        $consents = [];
        foreach (self::POLICY_KINDS as $kind) {
            $snapshot = (array) $snapshots->get($kind, []);
            if (! isset($snapshot['version'], $snapshot['checksum'])) {
                if ($kind === 'marketing_consent') {
                    continue;
                }

                return $this->reviewWithErrors($request, $propertySlug, $orderReference, ['booking' => [__('direct-booking.errors.policy_missing')]]);
            }
            $consents[$kind] = [
                'version' => (int) $snapshot['version'],
                'checksum' => (string) $snapshot['checksum'],
                'accepted' => $kind === 'marketing_consent'
                    ? (bool) data_get($facts, 'consent.marketing_consent', false)
                    : true,
            ];
        }
        $holdResponse = $this->call(fn () => $this->client->hold($request, $propertySlug, $orderReference, $credentials['session'], [
            'expected_state_version' => (int) $facts['expected_state_version'],
            'guest' => [
                'first_name' => $facts['first_name'],
                'last_name' => $facts['last_name'] ?? null,
                'email' => $facts['email'],
                'phone' => $facts['phone'] ?? null,
            ],
            'consents' => $consents,
            'turnstile_token' => $facts['turnstile_token'],
            'turnstile_action' => 'direct_booking_hold',
        ], $facts['hold_idempotency_key']));
        if (! $holdResponse?->successful()) {
            return $this->reviewWithErrors($request, $propertySlug, $orderReference, ['booking' => [$this->errorMessage($holdResponse)]]);
        }

        return redirect()->route('direct-booking.status', [$propertySlug, $orderReference]);
    }

    public function status(Request $request, string $propertySlug, string $orderReference): View
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference, fixtureFallback: true);
        if ($credentials === null) {
            return $this->unavailableView($request, $propertySlug);
        }
        $flow = (array) $request->session()->get($this->flowKey($propertySlug, $orderReference), []);
        $apiLocale = (string) data_get($flow, 'search.api_locale', $this->requestedLocale($request));
        $uiLocale = $this->flowLocale($request, $flow, $apiLocale);
        $propertyResponse = $this->propertyResponse($request, $propertySlug, $apiLocale);
        $statusResponse = $this->call(fn () => $this->client->status($request, $propertySlug, $orderReference, $credentials['session']));
        if (! $propertyResponse?->successful() || ! $statusResponse?->successful()) {
            return $this->unavailableView($request, $propertySlug, $statusResponse);
        }
        $property = (array) $propertyResponse->json('data', []);
        $locale = $this->activateLocale($uiLocale);
        $status = (array) $statusResponse->json('data', []);
        $confirmation = null;
        if (($status['state'] ?? null) === 'confirmed') {
            $confirmationResponse = $this->call(fn () => $this->client->confirmation($request, $propertySlug, $orderReference, $credentials['session']));
            if ($confirmationResponse?->successful()) {
                $confirmation = (array) $confirmationResponse->json('data', []);
            }
        }

        return view('direct-booking.status', [
            'property' => $property,
            'propertySlug' => $propertySlug,
            'orderReference' => $orderReference,
            'status' => $status,
            'confirmation' => $confirmation,
            'locale' => $locale,
            'quote' => (array) ($flow['quote'] ?? []),
            'fixtureQuery' => $this->fixtureQuery($request),
            'manualInstructions' => $this->manualInstructions($status, $flow),
            'confirmedDocuments' => $this->confirmedDocuments($confirmation),
        ]);
    }

    public function poll(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference, fixtureFallback: true);
        if ($credentials === null) {
            return response()->json(['state' => 'unavailable'], 404, ['Cache-Control' => 'no-store, private']);
        }
        $statusResponse = $this->call(fn () => $this->client->status($request, $propertySlug, $orderReference, $credentials['session']));
        if (! $statusResponse?->successful()) {
            return response()->json(['state' => 'unavailable'], $statusResponse?->status() ?? 503, ['Cache-Control' => 'no-store, private']);
        }
        $state = (string) $statusResponse->json('data.state', 'unavailable');

        return response()->json([
            'state' => $state,
            'state_version' => (int) $statusResponse->json('data.state_version', 0),
            'terminal' => in_array($state, ['confirmed', 'canceled', 'refunded', 'paid_needs_review'], true),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function document(Request $request, string $propertySlug, string $orderReference, string $documentReference): Response
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference);
        if ($credentials === null || preg_match('/^[a-f0-9]{64}$/', $documentReference) !== 1) {
            return response('', 404, ['Cache-Control' => 'no-store, private']);
        }
        $response = $this->call(fn () => $this->client->document(
            $request,
            $propertySlug,
            $orderReference,
            $documentReference,
            $credentials['session'],
        ));
        if (! $response?->successful()) {
            return response('', 404, ['Cache-Control' => 'no-store, private']);
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'application/pdf',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function checkout(Request $request, string $propertySlug, string $orderReference): RedirectResponse
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference, fixtureFallback: true);
        if ($credentials === null) {
            return redirect()->route('direct-booking.unavailable', $propertySlug);
        }
        $facts = $request->validate([
            'expected_state_version' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(['hosted_checkout', 'manual_bank_transfer'])],
            'checkout_idempotency_key' => ['required', 'uuid'],
        ]);
        $response = $this->call(fn () => $this->client->checkout($request, $propertySlug, $orderReference, $credentials['session'], [
            'expected_state_version' => (int) $facts['expected_state_version'],
            'method' => $facts['method'],
        ], $facts['checkout_idempotency_key']));
        if (! $response?->successful()) {
            return $this->statusError($propertySlug, $orderReference, $this->errorCode($response), $request);
        }
        $checkout = (array) $response->json('data', []);
        if ($facts['method'] === 'hosted_checkout') {
            $url = (string) ($checkout['checkout_url'] ?? '');
            if (! $this->validHostedCheckoutUrl($url)) {
                return $this->statusError($propertySlug, $orderReference, 'booking_unavailable', $request);
            }

            return redirect()->away($url);
        }

        $flowKey = $this->flowKey($propertySlug, $orderReference);
        $flow = (array) $request->session()->get($flowKey, []);
        $flow['manual_payment_instructions'] = (array) ($checkout['manual_payment_instructions'] ?? []);
        $request->session()->put($flowKey, $flow);

        return redirect()->route('direct-booking.status', [$propertySlug, $orderReference] + $this->fixtureState('awaiting_manual_payment'));
    }

    public function retryPayment(Request $request, string $propertySlug, string $orderReference): RedirectResponse
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference, fixtureFallback: true);
        if ($credentials === null) {
            return redirect()->route('direct-booking.unavailable', $propertySlug);
        }
        $facts = $request->validate([
            'expected_state_version' => ['required', 'integer', 'min:1'],
            'retry_idempotency_key' => ['required', 'uuid'],
        ]);
        $response = $this->call(fn () => $this->client->retryPayment($request, $propertySlug, $orderReference, $credentials['session'], [
            'expected_state_version' => (int) $facts['expected_state_version'],
        ], $facts['retry_idempotency_key']));
        if (! $response?->successful()) {
            return $this->statusError($propertySlug, $orderReference, $this->errorCode($response), $request);
        }
        $url = (string) $response->json('data.checkout_url', '');

        return $this->validHostedCheckoutUrl($url)
            ? redirect()->away($url)
            : redirect()->route('direct-booking.status', [$propertySlug, $orderReference]);
    }

    public function evidence(Request $request, string $propertySlug, string $orderReference): RedirectResponse
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference, fixtureFallback: true);
        if ($credentials === null) {
            return redirect()->route('direct-booking.unavailable', $propertySlug);
        }
        $facts = $request->validate([
            'expected_state_version' => ['required', 'integer', 'min:1'],
            'evidence_idempotency_key' => ['required', 'uuid'],
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $response = $this->call(fn () => $this->client->evidence(
            $request,
            $propertySlug,
            $orderReference,
            $credentials['session'],
            (int) $facts['expected_state_version'],
            $facts['evidence'],
            $facts['evidence_idempotency_key'],
        ));

        return $response?->successful()
            ? redirect()->route('direct-booking.status', [$propertySlug, $orderReference] + $this->fixtureState('evidence_pending'))
            : $this->statusError($propertySlug, $orderReference, $this->errorCode($response), $request);
    }

    public function recover(Request $request, string $propertySlug, string $orderReference): RedirectResponse
    {
        $credentials = $this->credentials($request, $propertySlug, $orderReference, fixtureFallback: true, allowExpiredSession: true);
        if ($credentials === null || ! isset($credentials['recovery'])) {
            return redirect()->route('direct-booking.unavailable', $propertySlug);
        }
        $facts = $request->validate([
            'expected_state_version' => ['required', 'integer', 'min:1'],
            'recover_idempotency_key' => ['required', 'uuid'],
            'arrival_date' => ['nullable', 'date_format:Y-m-d', 'required_with:departure_date', 'after_or_equal:today'],
            'departure_date' => ['nullable', 'date_format:Y-m-d', 'required_with:arrival_date', 'after:arrival_date'],
        ]);
        $payload = ['expected_state_version' => (int) $facts['expected_state_version']];
        if (filled($facts['arrival_date'] ?? null)) {
            $payload['arrival_date'] = $facts['arrival_date'];
            $payload['departure_date'] = $facts['departure_date'];
        }
        $response = $this->call(fn () => $this->client->recover($request, $propertySlug, $orderReference, $credentials['recovery'], $payload, $facts['recover_idempotency_key']));
        if (! $response?->successful()) {
            return $this->statusError($propertySlug, $orderReference, $this->errorCode($response), $request);
        }
        $data = (array) $response->json('data', []);
        $reference = (string) ($data['order_reference'] ?? $orderReference);
        $redirectQuery = ['recovered' => 1];
        if (filled($facts['arrival_date'] ?? null) && filled($facts['departure_date'] ?? null)) {
            $redirectQuery['arrival_date'] = $facts['arrival_date'];
            $redirectQuery['departure_date'] = $facts['departure_date'];
        }
        $redirect = redirect()->route('direct-booking.show', [$propertySlug] + $redirectQuery);

        return $this->withCredentials($redirect, $request, $propertySlug, $reference, (string) ($data['session_token'] ?? ''), (string) ($data['recovery_token'] ?? ''));
    }

    public function unavailable(Request $request, string $propertySlug): View
    {
        return $this->unavailableView($request, $propertySlug);
    }

    public function analytics(Request $request, string $propertySlug): Response
    {
        if (array_diff(array_keys($request->all()), ['event', 'locale']) !== []) {
            return response('', 422, ['Cache-Control' => 'no-store, private']);
        }
        $validator = Validator::make($request->all(), [
            'event' => ['required', 'string', Rule::in((array) config('direct-booking-ui.analytics_events'))],
            'locale' => ['required', 'string', Rule::in(['en', 'es', 'es-AR'])],
        ]);
        if ($validator->fails()) {
            return response('', 422, ['Cache-Control' => 'no-store, private']);
        }

        return response('', 204, ['Cache-Control' => 'no-store, private']);
    }

    /** @param array<string, list<string>> $errors */
    private function reviewWithErrors(Request $request, string $propertySlug, string $orderReference, array $errors): View
    {
        $view = $this->review($request, $propertySlug, $orderReference);
        $view->with('bookingErrors', new MessageBag($errors));

        return $view;
    }

    private function unavailableView(Request $request, string $propertySlug, ?ClientResponse $response = null): View
    {
        $this->activateLocale($this->requestedLocale($request));

        return view('direct-booking.unavailable', [
            'propertySlug' => $propertySlug,
            'errorCode' => $this->errorCode($response),
            'correlationId' => is_string($response?->json('error.correlation_id')) ? $response->json('error.correlation_id') : null,
        ]);
    }

    /** @param array<string, mixed> $query */
    private function landingError(string $propertySlug, string $code, array $query = []): RedirectResponse
    {
        $safeQuery = Arr::only($query, ['arrival_date', 'departure_date', 'adults', 'children', 'infants', 'currency', 'locale', 'program_key']);
        $uiLocale = (string) ($query['ui_locale'] ?? $query['lang'] ?? '');
        if ($uiLocale !== '') {
            $safeQuery['lang'] = str_starts_with(strtolower($uiLocale), 'es') ? 'es' : 'en';
        }

        return redirect()->route('direct-booking.show', [$propertySlug] + array_filter($safeQuery, fn ($value) => is_scalar($value)))
            ->with('booking_error', $code);
    }

    private function statusError(string $propertySlug, string $reference, string $code, Request $request): RedirectResponse
    {
        return redirect()->route('direct-booking.status', [$propertySlug, $reference] + $this->fixtureQuery($request))
            ->with('booking_error', $code);
    }

    /** @return array<string, list<mixed>> */
    private function searchRules(array $property): array
    {
        return [
            'arrival_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after:arrival_date'],
            'adults' => ['required', 'integer', 'min:1', 'max:50'],
            'children' => ['required', 'integer', 'min:0', 'max:50'],
            'infants' => ['required', 'integer', 'min:0', 'max:20'],
            'currency' => ['required', Rule::in((array) ($property['supported_currencies'] ?? []))],
            'locale' => ['required', Rule::in((array) ($property['supported_locales'] ?? []))],
            'program_key' => ['nullable', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function quoteRules(array $property): array
    {
        return $this->searchRules($property) + [
            'option_key' => ['required', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'voucher_code' => ['nullable', 'string', 'min:4', 'max:80'],
            'optional_service_keys' => ['sometimes', 'array'],
            'optional_service_keys.*' => ['regex:/^[0-9A-HJKMNP-TV-Z]{26}$/', 'distinct'],
            'turnstile_token' => ['required', 'string', 'max:2048'],
            'begin_idempotency_key' => ['required', 'uuid'],
            'quote_idempotency_key' => ['required', 'uuid'],
            'attribution' => ['sometimes', 'array'],
            'ui_locale' => ['sometimes', Rule::in(['en', 'es-AR'])],
        ];
    }

    /** @return array<string, mixed> */
    private function availabilityFacts(array $facts): array
    {
        return [
            'arrival_date' => (string) $facts['arrival_date'],
            'departure_date' => (string) $facts['departure_date'],
            'occupancy' => [
                'adults' => (int) $facts['adults'],
                'children' => (int) $facts['children'],
                'infants' => (int) $facts['infants'],
            ],
            'currency' => (string) $facts['currency'],
            'locale' => (string) $facts['locale'],
            'program_key' => filled($facts['program_key'] ?? null) ? (string) $facts['program_key'] : null,
        ];
    }

    /** @param array<string, mixed> $quote @return array{0: array<string, array<string, mixed>>, 1: bool} */
    private function loadPolicies(Request $request, string $propertySlug, array $quote, string $apiLocale): array
    {
        $snapshots = collect((array) ($quote['policies'] ?? []))->keyBy('kind');
        $policies = [];
        $ready = true;

        foreach (self::POLICY_KINDS as $kind) {
            $snapshot = (array) $snapshots->get($kind, []);
            if ($snapshot === [] && $kind === 'marketing_consent') {
                continue;
            }

            $response = $snapshot === []
                ? null
                : $this->call(fn () => $this->client->policy($request, $propertySlug, $kind, $apiLocale));
            $policy = $response?->successful() ? (array) $response->json('data', []) : [];
            if (! $this->matchesPolicySnapshot($policy, $snapshot, $kind, $apiLocale)) {
                $ready = false;
                $policies[$kind] = [
                    'kind' => $kind,
                    'title' => __('direct-booking.policy.'.$kind),
                    'body' => __('direct-booking.policy.unavailable'),
                ];

                continue;
            }

            $policies[$kind] = $policy;
        }

        return [$policies, $ready];
    }

    /** @param array<string, mixed> $policy @param array<string, mixed> $snapshot */
    private function matchesPolicySnapshot(array $policy, array $snapshot, string $kind, string $apiLocale): bool
    {
        $checksum = $snapshot['checksum'] ?? null;
        $version = $snapshot['version'] ?? null;
        if (! is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1 || ! is_int($version)) {
            return false;
        }

        return ($policy['kind'] ?? null) === $kind
            && ($policy['locale'] ?? null) === $apiLocale
            && ($policy['version'] ?? null) === $version
            && is_string($policy['checksum'] ?? null)
            && hash_equals($checksum, $policy['checksum'])
            && is_string($policy['title'] ?? null)
            && trim($policy['title']) !== ''
            && is_string($policy['body'] ?? null)
            && trim($policy['body']) !== '';
    }

    /** @return array<string, string> */
    private function attribution(Request $request, array $submitted = []): array
    {
        $source = $submitted + $request->only(DirectBookingPrivacy::ATTRIBUTION_KEYS);
        $source['landing_path'] = $request->getPathInfo();
        $referrer = $request->headers->get('referer');
        if (is_string($referrer) && is_string(parse_url($referrer, PHP_URL_HOST))) {
            $source['referrer_host'] = parse_url($referrer, PHP_URL_HOST);
        }

        return app(DirectBookingPrivacy::class)->attribution($source);
    }

    /** @return array{site_key: string|null, mock_token: string|null, action: string} */
    private function turnstile(string $action): array
    {
        $siteKey = trim((string) config('direct-booking-ui.turnstile_site_key'));
        $mockToken = trim((string) config('direct-booking-ui.contract_mock_turnstile_token'));
        if ($siteKey === '' && $mockToken === '') {
            $mockToken = 'bot-verification-not-required';
        }

        return ['site_key' => $siteKey !== '' ? $siteKey : null, 'mock_token' => $mockToken !== '' ? $mockToken : null, 'action' => $action];
    }

    /** @return array{session: string, recovery?: string}|null */
    private function credentials(Request $request, string $propertySlug, string $reference, bool $fixtureFallback = false, bool $allowExpiredSession = false): ?array
    {
        if (! preg_match(self::ORDER_REFERENCE_PATTERN, $reference)) {
            return null;
        }
        $session = (string) $request->cookie($this->cookieName('session', $propertySlug), '');
        $recovery = (string) $request->cookie($this->cookieName('recovery', $propertySlug), '');
        $boundReference = (string) $request->cookie($this->cookieName('order', $propertySlug), '');
        if ($fixtureFallback && (bool) config('direct-booking-ui.allow_fixture_controls') && $request->hasAny(['fixture_state', 'fixture_error'])) {
            $session = str_repeat('A', 64);
            $recovery = str_repeat('R', 64);
            $boundReference = $reference;
        }
        if (! hash_equals($reference, $boundReference) || (! $allowExpiredSession && ! $this->validToken($session))) {
            return null;
        }
        $credentials = ['session' => $session];
        if ($this->validToken($recovery)) {
            $credentials['recovery'] = $recovery;
        }

        return $credentials;
    }

    private function withCredentials(RedirectResponse $response, Request $request, string $propertySlug, string $reference, string $sessionToken, string $recoveryToken): RedirectResponse
    {
        if (! $this->validToken($sessionToken) || ! $this->validToken($recoveryToken)) {
            return $response;
        }
        $path = '/book/'.$propertySlug;
        $secure = $request->isSecure();
        $response->withCookie(Cookie::make($this->cookieName('session', $propertySlug), $sessionToken, 120, $path, null, $secure, true, false, 'lax'));
        $response->withCookie(Cookie::make($this->cookieName('recovery', $propertySlug), $recoveryToken, 10080, $path, null, $secure, true, false, 'lax'));
        $response->withCookie(Cookie::make($this->cookieName('order', $propertySlug), $reference, 10080, $path, null, $secure, true, false, 'lax'));

        return $response;
    }

    private function cookieName(string $kind, string $propertySlug): string
    {
        return 'inn_booking_'.$kind.'_'.substr(hash('sha256', $propertySlug), 0, 12);
    }

    private function flowKey(string $propertySlug, string $reference): string
    {
        return 'direct_booking_ui.'.hash('sha256', $propertySlug.':'.$reference);
    }

    private function requestedLocale(Request $request): string
    {
        $locale = strtolower((string) $request->input('ui_locale', $request->query('lang', $request->input('locale', 'en'))));

        return str_starts_with($locale, 'es') ? 'es-AR' : 'en';
    }

    private function activateLocale(string $locale): string
    {
        $normalized = str_starts_with(strtolower($locale), 'es') ? 'es-AR' : 'en';
        app()->setLocale(str_starts_with($normalized, 'es') ? 'es' : 'en');

        return $normalized;
    }

    private function errorCode(?ClientResponse $response): string
    {
        $code = $response?->json('error.code');

        return is_string($code) && preg_match('/^[a-z_]{3,40}$/', $code) ? $code : 'booking_unavailable';
    }

    private function errorMessage(?ClientResponse $response): string
    {
        return __('direct-booking.errors.'.$this->errorCode($response));
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1;
    }

    private function validHostedCheckoutUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host)) {
            return false;
        }
        if ((bool) config('direct-booking-ui.allow_fixture_controls') && $host === 'checkout.example.test') {
            return true;
        }

        return $host === 'mercadopago.com'
            || $host === 'mercadopago.com.ar'
            || $host === 'mercadopago.com.co'
            || str_ends_with($host, '.mercadopago.com')
            || str_ends_with($host, '.mercadopago.com.ar')
            || str_ends_with($host, '.mercadopago.com.co');
    }

    private function call(callable $operation): ?ClientResponse
    {
        try {
            return $operation();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, string> */
    private function fixtureQuery(Request $request): array
    {
        if (! (bool) config('direct-booking-ui.allow_fixture_controls')) {
            return [];
        }

        return array_filter([
            'fixture_state' => is_string($request->query('fixture_state')) ? $request->query('fixture_state') : null,
            'fixture_error' => is_string($request->query('fixture_error')) ? $request->query('fixture_error') : null,
        ], fn ($value) => is_string($value) && preg_match('/^[a-z_]{3,40}$/', $value));
    }

    /** @return array<string, string> */
    private function fixtureState(string $state): array
    {
        return (bool) config('direct-booking-ui.allow_fixture_controls') ? ['fixture_state' => $state] : [];
    }

    /** @param array<string, mixed> $flow @return array<string, mixed>|null */
    private function manualInstructions(array $status, array $flow): ?array
    {
        if (($status['state'] ?? null) !== 'awaiting_manual_payment') {
            return null;
        }

        $instructions = (array) ($flow['manual_payment_instructions'] ?? []);
        if (isset($instructions['title'], $instructions['body'], $instructions['version'], $instructions['currency'])) {
            return $instructions;
        }
        if (! (bool) config('direct-booking-ui.allow_fixture_controls')) {
            return null;
        }

        return [
            'title' => __('direct-booking.manual.instructions_title'),
            'body' => __('direct-booking.manual.instructions_mock'),
            'version' => 'contract-mock-v1',
            'currency' => 'USD',
        ];
    }

    /** @return list<array{kind: string, document_reference: string, download_path: string}> */
    private function confirmedDocuments(?array $confirmation): array
    {
        if ($confirmation === null) {
            return [];
        }

        return collect((array) ($confirmation['documents'] ?? []))
            ->map(fn (mixed $document): array => [
                'kind' => (string) data_get($document, 'kind', ''),
                'document_reference' => (string) data_get($document, 'document_reference', ''),
                'download_path' => (string) data_get($document, 'download_path', ''),
            ])
            ->filter(function (array $document): bool {
                $path = $document['download_path'];

                return $document['kind'] !== ''
                    && preg_match('/^[a-f0-9]{64}$/', $document['document_reference']) === 1
                    && preg_match('#^/api/v1/direct-booking/properties/[a-z0-9-]+/orders/[0-9A-HJKMNP-TV-Z]{26}/confirmation/documents/[a-f0-9]{64}$#', $path) === 1;
            })
            ->values()->all();
    }

    private function propertyResponse(Request $request, string $propertySlug, string $locale): ?ClientResponse
    {
        $response = $this->call(fn () => $this->client->property($request, $propertySlug, $locale));
        if ($response?->successful() || $locale === 'en') {
            return $response;
        }

        return $this->call(fn () => $this->client->property($request, $propertySlug, 'en'));
    }

    /** @param array<string, mixed> $flow */
    private function flowLocale(Request $request, array $flow, string $fallback): string
    {
        $stored = (string) data_get($flow, 'search.ui_locale', $fallback);
        $locale = $request->hasAny(['lang', 'ui_locale'])
            ? $this->requestedLocale($request)
            : $stored;

        if ($locale !== $stored && isset($flow['search']) && is_array($flow['search'])) {
            $flow['search']['ui_locale'] = $locale;
            $request->session()->put($this->flowKey(
                (string) $request->route('propertySlug'),
                (string) $request->route('orderReference'),
            ), $flow);
        }

        return $this->activateLocale($locale);
    }
}
