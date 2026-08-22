<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DirectBookingErrorCode;
use App\Exceptions\DirectBookingContractException;
use App\Http\Controllers\Controller;
use App\Http\Responses\DirectBookingErrorResponse;
use App\Models\DirectBookingPropertySetting;
use App\Services\DirectBooking\DirectBookingApiService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DirectBookingContractController extends Controller
{
    public function __construct(private readonly DirectBookingApiService $api) {}

    public function property(Request $request): JsonResponse
    {
        return $this->execute($request, fn () => $this->api->property($this->setting($request), $this->locale($request)), published: true);
    }

    public function policy(Request $request, string $propertySlug, string $policyKind): JsonResponse
    {
        return $this->execute($request, fn () => $this->api->policy($this->setting($request), $this->locale($request), $policyKind), published: true);
    }

    public function availability(Request $request): JsonResponse
    {
        return $this->execute($request, function () use ($request): array {
            $this->only($request, ['arrival_date', 'departure_date', 'occupancy', 'category_key', 'program_key', 'currency', 'locale']);

            return $this->api->availability($this->setting($request), $request->validate($this->availabilityRules()));
        });
    }

    public function begin(Request $request): JsonResponse
    {
        return $this->execute($request, function () use ($request): array {
            $this->only($request, ['locale', 'currency', 'turnstile_token', 'turnstile_action', 'attribution']);
            $data = $request->validate([
                'locale' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
                'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                'turnstile_token' => ['required', 'string', 'max:2048'],
                'turnstile_action' => ['required', Rule::in(['direct_booking_begin'])],
                'attribution' => ['sometimes', 'array:utm_source,utm_medium,utm_campaign,utm_content,utm_term,referrer_host,landing_path'],
                'attribution.*' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            ]);

            return $this->api->begin($this->setting($request), $data, $request, $this->retry($request));
        }, successStatus: 201);
    }

    public function quote(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        return $this->execute($request, function () use ($request, $orderReference): array {
            $this->only($request, ['arrival_date', 'departure_date', 'occupancy', 'category_key', 'program_key', 'currency', 'locale', 'expected_state_version', 'optional_service_keys', 'voucher_code']);
            $data = $request->validate([
                ...$this->availabilityRules(),
                'expected_state_version' => ['required', 'integer', 'min:1'],
                'optional_service_keys' => ['sometimes', 'array', 'max:50'],
                'optional_service_keys.*' => ['string', 'distinct', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
                'voucher_code' => ['nullable', 'string', 'min:4', 'max:80'],
            ]);
            $setting = $this->setting($request);

            return $this->api->quote($setting, $this->api->resolveOrder($setting, $orderReference, $request->bearerToken()), $data, $this->retry($request));
        });
    }

    public function hold(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        return $this->execute($request, function () use ($request, $orderReference): array {
            $this->only($request, ['expected_state_version', 'guest', 'companions', 'consents', 'turnstile_token', 'turnstile_action']);
            $data = $request->validate([
                'expected_state_version' => ['required', 'integer', 'min:1'],
                'guest' => ['required', 'array:first_name,last_name,email,phone'],
                'guest.first_name' => ['required', 'string', 'max:100'],
                'guest.last_name' => ['nullable', 'string', 'max:100'],
                'guest.email' => ['required', 'email:rfc', 'max:254'],
                'guest.phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 .()\/\-]{7,40}$/'],
                'companions' => ['sometimes', 'array', 'max:49'],
                'companions.*' => ['array:first_name,last_name,guest_type'],
                'companions.*.first_name' => ['required', 'string', 'max:100'],
                'companions.*.last_name' => ['nullable', 'string', 'max:100'],
                'companions.*.guest_type' => ['required', Rule::in(['adult', 'child', 'infant'])],
                'consents' => ['required', 'array:terms,privacy,cancellation,no_show,marketing_consent'],
                'consents.terms' => ['required', 'array'],
                'consents.privacy' => ['required', 'array'],
                'consents.cancellation' => ['required', 'array'],
                'consents.no_show' => ['required', 'array'],
                'consents.marketing_consent' => ['sometimes', 'array'],
                'consents.*' => ['array:version,checksum,accepted'],
                'consents.*.version' => ['required', 'integer', 'min:1'],
                'consents.*.checksum' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
                'consents.*.accepted' => ['required', 'boolean'],
                'turnstile_token' => ['required', 'string', 'max:2048'],
                'turnstile_action' => ['required', Rule::in(['direct_booking_hold'])],
            ]);
            $setting = $this->setting($request);

            return $this->api->hold($setting, $this->api->resolveOrder($setting, $orderReference, $request->bearerToken()), $data, $request, $this->retry($request));
        });
    }

    public function status(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        return $this->execute($request, function () use ($request, $orderReference): array {
            $setting = $this->setting($request);

            return $this->api->status($setting, $this->api->resolveOrder($setting, $orderReference, $request->bearerToken()));
        });
    }

    public function checkout(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        return $this->execute($request, function () use ($request, $orderReference): array {
            $this->only($request, ['expected_state_version', 'method']);
            $data = $request->validate([
                'expected_state_version' => ['required', 'integer', 'min:1'],
                'method' => ['required', Rule::in(['hosted_checkout', 'manual_bank_transfer'])],
            ]);
            $setting = $this->setting($request);

            return $this->api->checkout($setting, $this->api->resolveOrder($setting, $orderReference, $request->bearerToken()), $data, $this->retry($request));
        });
    }

    public function retryPayment(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        return $this->execute($request, function () use ($request, $orderReference): array {
            $this->only($request, ['expected_state_version']);
            $data = $request->validate(['expected_state_version' => ['required', 'integer', 'min:1']]);
            $setting = $this->setting($request);

            return $this->api->retryPayment($setting, $this->api->resolveOrder($setting, $orderReference, $request->bearerToken()), $data, $this->retry($request));
        });
    }

    public function manualEvidence(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        return $this->execute($request, function () use ($request, $orderReference): array {
            $this->only($request, ['expected_state_version', 'evidence']);
            $data = $request->validate([
                'expected_state_version' => ['required', 'integer', 'min:1'],
                'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            ]);
            $setting = $this->setting($request);
            $order = $this->api->resolveOrder($setting, $orderReference, $request->bearerToken());

            return $this->api->manualEvidence($setting, $order, (int) $data['expected_state_version'], $request->file('evidence'), $this->retry($request));
        }, successStatus: 202);
    }

    public function recover(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        return $this->execute($request, function () use ($request, $orderReference): array {
            $this->only($request, ['expected_state_version', 'arrival_date', 'departure_date']);
            $data = $request->validate([
                'expected_state_version' => ['required', 'integer', 'min:1'],
                'arrival_date' => ['sometimes', 'date_format:Y-m-d'],
                'departure_date' => ['sometimes', 'date_format:Y-m-d', 'after:arrival_date'],
            ]);

            return $this->api->recover(
                $this->setting($request),
                $orderReference,
                (string) $request->bearerToken(),
                (int) $data['expected_state_version'],
                $this->retry($request),
                $data,
            );
        });
    }

    public function confirmation(Request $request, string $propertySlug, string $orderReference): JsonResponse
    {
        return $this->execute($request, function () use ($request, $orderReference): array {
            $setting = $this->setting($request);

            return $this->api->confirmation($setting, $this->api->resolveOrder($setting, $orderReference, $request->bearerToken()));
        });
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(Request $request, callable $callback, bool $published = false, int $successStatus = 200): JsonResponse
    {
        $correlation = $request->header('X-Correlation-ID');
        if (! is_string($correlation) || preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $correlation) !== 1) {
            $correlation = (string) Str::uuid();
        }
        $request->attributes->set('direct_booking_correlation_id', $correlation);
        try {
            $data = $callback();

            return response()->json(['data' => $data], $successStatus, [
                'Cache-Control' => $published ? 'public, max-age=60, stale-while-revalidate=300' : 'no-store, private',
                'X-Correlation-ID' => $correlation,
                ...($published ? ['Content-Language' => $data['locale'] ?? $this->locale($request)] : []),
            ]);
        } catch (DirectBookingContractException $exception) {
            return DirectBookingErrorResponse::make($request, $exception->errorCode);
        } catch (ValidationException $exception) {
            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::Validation, $exception->errors());
        } catch (AuthenticationException) {
            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::NotFound);
        } catch (Throwable $exception) {
            report($exception);

            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::BookingUnavailable);
        }
    }

    private function setting(Request $request): DirectBookingPropertySetting
    {
        $setting = $request->attributes->get('direct_booking_setting');
        abort_unless($setting instanceof DirectBookingPropertySetting, 404);

        return $setting;
    }

    private function locale(Request $request): string
    {
        $setting = $this->setting($request);
        $locale = $request->query('locale', $setting->default_locale);

        return is_string($locale) ? $locale : $setting->default_locale;
    }

    private function retry(Request $request): string
    {
        return strtolower((string) $request->header('Idempotency-Key'));
    }

    /** @return array<string, mixed> */
    private function availabilityRules(): array
    {
        return [
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after:arrival_date'],
            'occupancy' => ['required', 'array:adults,children,infants'],
            'occupancy.adults' => ['required', 'integer', 'min:1', 'max:50'],
            'occupancy.children' => ['required', 'integer', 'min:0', 'max:50'],
            'occupancy.infants' => ['required', 'integer', 'min:0', 'max:20'],
            'category_key' => ['nullable', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'program_key' => ['nullable', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'locale' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
        ];
    }

    /** @param list<string> $allowed */
    private function only(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected request properties are not accepted.']);
        }
    }
}
