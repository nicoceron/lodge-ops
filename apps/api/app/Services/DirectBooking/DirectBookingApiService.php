<?php

namespace App\Services\DirectBooking;

use App\Contracts\DirectBooking\BotVerifier;
use App\Enums\AllocationStatus;
use App\Enums\DepositStatus;
use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingPaymentMethod;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\ReservationStatus;
use App\Exceptions\DirectBookingContractException;
use App\Models\Allocation;
use App\Models\BookingQuote;
use App\Models\Deposit;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\GuestPaymentEvidence;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Services\AvailabilityQuery;
use App\Services\AvailabilityService;
use App\Services\BookingQuoteService;
use App\Services\CommitBookingQuote;
use App\Services\Documents\CanonicalJson;
use App\Services\PaymentEvidenceScanner;
use App\Services\Payments\CreateProviderCheckout;
use App\Services\ResourceSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DirectBookingApiService
{
    public function __construct(
        private readonly DirectBookingLaunchReadinessEvaluator $readiness,
        private readonly DirectBookingSafeProjection $safeProjection,
        private readonly DirectBookingTokenService $tokens,
        private readonly DirectBookingStateMachine $states,
        private readonly DirectBookingConsentRecorder $consents,
        private readonly AvailabilityQuery $availability,
        private readonly AvailabilityService $inventory,
        private readonly BookingQuoteService $quotes,
        private readonly CommitBookingQuote $commitQuote,
        private readonly IssueDirectBookingPaymentRequest $issuePaymentRequest,
        private readonly CreateProviderCheckout $providerCheckout,
        private readonly BotVerifier $botVerifier,
        private readonly CanonicalJson $canonical,
        private readonly PaymentEvidenceScanner $evidenceScanner,
        private readonly ResourceSuggestionService $resourceSuggestions,
    ) {}

    /** @return array<string, mixed> */
    public function property(DirectBookingPropertySetting $setting, string $locale): array
    {
        $this->assertReady($setting);
        $this->assertLocaleCurrency($setting, $locale, null);

        return $this->safeProjection->property($setting, $locale);
    }

    /** @return array<string, mixed> */
    public function policy(DirectBookingPropertySetting $setting, string $locale, string $kind): array
    {
        $this->assertReady($setting);
        $this->assertLocaleCurrency($setting, $locale, null);
        $policyKind = DirectBookingPublicationKind::tryFrom($kind);
        if ($policyKind === null || ! in_array($policyKind, [
            DirectBookingPublicationKind::Terms,
            DirectBookingPublicationKind::Privacy,
            DirectBookingPublicationKind::Cancellation,
            DirectBookingPublicationKind::NoShow,
            DirectBookingPublicationKind::MarketingConsent,
        ], true)) {
            throw new AuthenticationException;
        }
        $publication = DirectBookingPublication::query()
            ->where('property_id', $setting->property_id)->whereNull('public_item_id')
            ->where('kind', $policyKind)->where('locale', $locale)
            ->where('state', DirectBookingPublicationState::Published)
            ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->first();
        if ($publication === null || blank($publication->body)) {
            throw new AuthenticationException;
        }

        return [
            'kind' => $publication->kind->value,
            'locale' => $publication->locale,
            'version' => $publication->version,
            'checksum' => $publication->checksum,
            'title' => $publication->title,
            'body' => $publication->body,
            'effective_at' => ($publication->effective_at ?? $publication->published_at ?? $publication->created_at)->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function availability(DirectBookingPropertySetting $setting, array $data): array
    {
        $this->assertReady($setting);
        $this->assertLocaleCurrency($setting, $data['locale'], $data['currency']);
        $items = $this->items($setting);
        $category = $this->item($items, $data['category_key'] ?? null, 'category');
        $program = $this->item($items, $data['program_key'] ?? null, 'program');
        $occupancy = (int) $data['occupancy']['adults'] + (int) $data['occupancy']['children'];
        $result = $this->availability->forStay(
            $setting->property_id,
            $this->localDate($setting, $data['arrival_date']),
            $this->localDate($setting, $data['departure_date']),
            $occupancy,
            $category?->resource_category_id,
        );
        if ($program !== null) {
            $programModel = Program::query()->with('requirements.category')->whereKey($program->program_id)
                ->where('is_active', true)->first();
            $result['programs'] = [[
                'id' => $program->program_id,
                'available' => $programModel !== null
                    && collect($result['categories'])->contains('available', true)
                    && $programModel->requirements->every(fn ($requirement): bool => $this->resourceSuggestions->suggest(
                        $requirement->category,
                        CarbonImmutable::parse($this->localDate($setting, $data['arrival_date'])),
                        CarbonImmutable::parse($this->localDate($setting, $data['departure_date'])),
                        $requirement->quantityForParty($occupancy),
                        $requirement->capabilities ?? [],
                        $requirement->languages ?? [],
                        $setting->property_id,
                    )->isNotEmpty()),
            ]];
        }

        return [
            'arrival_date' => $data['arrival_date'],
            'departure_date' => $data['departure_date'],
            'timezone' => $setting->property->timezone,
            'currency' => strtoupper($data['currency']),
            'options' => $this->safeProjection->availability($setting, $result),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function begin(DirectBookingPropertySetting $setting, array $data, Request $request, string $retryIdentity): array
    {
        $this->assertReady($setting);
        $this->assertLocaleCurrency($setting, $data['locale'], $data['currency']);
        $this->verifyBot($setting, $data, $request, 'direct_booking_begin', $retryIdentity);
        $issued = $this->tokens->issue($setting, $data['locale'], $data['currency'], $data['attribution'] ?? [], $request->ip());
        $order = $issued['order'];

        return [
            'order_reference' => $order->public_reference,
            'session_token' => $issued['token'],
            'recovery_token' => $issued['recovery_token'],
            'state' => $order->state->value,
            'state_version' => $order->state_version,
            'locale' => $order->locale,
            'currency' => $order->currency,
            'session_expires_at' => $order->session_expires_at->toIso8601String(),
            'recovery_expires_at' => $order->recovery_expires_at->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function quote(DirectBookingPropertySetting $setting, DirectBookingOrder $order, array $data, string $retryIdentity): array
    {
        $this->assertReady($setting);
        $this->assertLocaleCurrency($setting, $data['locale'], $data['currency']);
        if (! hash_equals($order->locale, $data['locale']) || ! hash_equals($order->currency, strtoupper($data['currency']))) {
            throw ValidationException::withMessages(['currency' => 'The quote locale and currency must match the booking session.']);
        }
        if ($order->state !== DirectBookingOrderState::Started || $order->state_version !== (int) $data['expected_state_version']) {
            throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'Refresh the booking before requesting another quote.');
        }
        $items = $this->items($setting);
        $category = $this->item($items, $data['category_key'] ?? null, 'category')
            ?? $items->firstWhere('kind', 'category');
        if (! $category instanceof DirectBookingPublicItem) {
            throw ValidationException::withMessages(['category_key' => 'A published accommodation selection is required.']);
        }
        $program = $this->item($items, $data['program_key'] ?? null, 'program');
        $plan = RatePlan::query()->where('property_id', $setting->property_id)
            ->where('currency', strtoupper($data['currency']))->where('state', 'published')->where('is_active', true)
            ->whereHas('rules', fn ($query) => $query->whereNull('resource_category_id')->orWhere('resource_category_id', $category->resource_category_id))
            ->orderByDesc('version')->orderBy('id')->first();
        if ($plan === null) {
            throw new DirectBookingContractException(DirectBookingErrorCode::Unavailable, 'The selected stay is unavailable.');
        }
        if (($data['optional_service_keys'] ?? []) !== []) {
            throw ValidationException::withMessages(['optional_service_keys' => 'The requested optional service selection is unavailable.']);
        }
        $occupancy = $data['occupancy'];
        $quote = $this->quotes->create([
            'property_id' => $setting->property_id,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->resource_category_id,
            'program_id' => $program?->program_id,
            'starts_at' => $this->localDate($setting, $data['arrival_date']),
            'ends_at' => $this->localDate($setting, $data['departure_date']),
            'adults' => $occupancy['adults'],
            'children' => $occupancy['children'],
            'infants' => $occupancy['infants'],
            'voucher_code' => $data['voucher_code'] ?? null,
            'promotion_session_id' => $order->public_reference,
        ]);
        $order->forceFill(['booking_quote_id' => $quote->id])->save();
        $transition = $this->states->transition(
            $order,
            DirectBookingOrderState::Quoted,
            DirectBookingTransitionAuthority::Pricing,
            (int) $data['expected_state_version'],
            'quote:'.$retryIdentity,
        );

        return $this->quoteProjection($transition->order, $quote);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function hold(DirectBookingPropertySetting $setting, DirectBookingOrder $order, array $data, Request $request, string $retryIdentity): array
    {
        $this->assertReady($setting);
        if ($order->state !== DirectBookingOrderState::Quoted || $order->state_version !== (int) $data['expected_state_version']) {
            throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'Refresh the booking before committing the hold.');
        }
        $this->verifyBot($setting, $data, $request, 'direct_booking_hold', $retryIdentity);
        $guest = $this->validatedGuest($data['guest']);

        return DB::transaction(function () use ($order, $data, $request, $retryIdentity, $guest): array {
            $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
            $quote = BookingQuote::query()->with('lines')->lockForUpdate()->findOrFail($locked->booking_quote_id);
            $decisions = [];
            foreach ($data['consents'] as $kind => $decision) {
                if (in_array($kind, ['terms', 'privacy', 'cancellation', 'no_show'], true)
                    && $decision['accepted'] !== true) {
                    throw ValidationException::withMessages(["consents.{$kind}.accepted" => 'This required booking policy must be accepted.']);
                }
                $publication = DirectBookingPublication::query()
                    ->where('property_id', $locked->property_id)->whereNull('public_item_id')
                    ->where('kind', $kind)->where('locale', $locked->locale)
                    ->where('state', DirectBookingPublicationState::Published)
                    ->where('version', $decision['version'])->where('checksum', $decision['checksum'])
                    ->first();
                if ($publication === null) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'A submitted policy version is unavailable.', 422);
                }
                $decisions[$kind] = ['publication_id' => $publication->id, 'accepted' => (bool) $decision['accepted']];
            }
            $this->consents->record($locked, $decisions, $request->ip());
            $reservation = $this->commitQuote->handle($quote, null, $guest, source: 'direct');
            $this->provisionProgramRequirements($reservation);
            $locked->forceFill([
                'reservation_id' => $reservation->id,
                'guest_contact_encrypted' => $guest,
                'guest_contact_checksum' => $this->canonical->checksum($guest),
            ])->save();
            $transition = $this->states->transition(
                $locked,
                DirectBookingOrderState::Held,
                DirectBookingTransitionAuthority::Inventory,
                (int) $data['expected_state_version'],
                'hold:'.$retryIdentity,
            );

            return $this->statusProjection($transition->order);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function status(DirectBookingPropertySetting $setting, DirectBookingOrder $order): array
    {
        $this->assertReady($setting);

        return $this->statusProjection($order->fresh());
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function checkout(DirectBookingPropertySetting $setting, DirectBookingOrder $order, array $data, string $retryIdentity): array
    {
        $this->assertReady($setting);
        $method = DirectBookingPaymentMethod::from($data['method']);
        $capability = DirectBookingPaymentCapability::query()
            ->where('property_id', $setting->property_id)->where('currency', $order->currency)
            ->where('method', $method)->where('is_enabled', true)->with('providerConnection')->first();
        if ($capability === null) {
            throw new DirectBookingContractException(DirectBookingErrorCode::Unavailable, 'The selected payment method is unavailable.');
        }
        if ($method === DirectBookingPaymentMethod::ManualBankTransfer) {
            $transition = DB::transaction(function () use ($order, $data, $retryIdentity) {
                $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
                $quote = $locked->bookingQuote()->lockForUpdate()->firstOrFail();
                $reservation = $locked->reservation()->lockForUpdate()->firstOrFail();
                $this->assertLiveHold($locked, $reservation);
                [$schedule, $amount] = $this->quotedDeposit($quote);
                Deposit::query()->firstOrCreate([
                    'reservation_id' => $reservation->id,
                    'schedule_type' => $schedule,
                ], [
                    'status' => DepositStatus::Due,
                    'currency' => $quote->currency,
                    'amount_minor' => $amount,
                    'due_at' => now(),
                ]);

                return $this->states->transition(
                    $locked,
                    DirectBookingOrderState::AwaitingManualPayment,
                    DirectBookingTransitionAuthority::PaymentOrchestrator,
                    (int) $data['expected_state_version'],
                    'manual:'.$retryIdentity,
                );
            }, 3);

            return $this->checkoutProjection($transition->order, $method, null);
        }

        $issued = $this->issuePaymentRequest->handle($order, (int) $data['expected_state_version'], 'request:'.$retryIdentity);
        $connection = $capability->providerConnection;
        if ($connection === null) {
            throw new DirectBookingContractException(DirectBookingErrorCode::BookingUnavailable, 'Hosted checkout is temporarily unavailable.', 503);
        }
        // The provider call intentionally occurs after the local hold/request transaction.
        // Retrying a Creating attempt reuses the same provider idempotency identity.
        $attempt = $this->providerCheckout->handle($issued['request'], $connection);
        $current = $order->fresh();
        if ($attempt->checkout_expires_at?->greaterThan($current->hold_expires_at) === true && $current->hold_extended_at === null) {
            $remainingBound = max(0, (int) $current->hold_expires_at->diffInMinutes($current->held_at->addMinutes($setting->maximum_hold_minutes), false));
            $providerDelta = max(0, (int) $current->hold_expires_at->diffInMinutes($attempt->checkout_expires_at, false));
            $extension = min($setting->checkout_extension_minutes, $remainingBound, $providerDelta);
            if ($extension > 0) {
                $current = $this->states->transition(
                    $current,
                    DirectBookingOrderState::PaymentPending,
                    DirectBookingTransitionAuthority::PaymentOrchestrator,
                    $current->state_version,
                    'extend:'.$retryIdentity,
                    ['hold_extension_minutes' => $extension],
                )->order;
            }
        }

        return $this->checkoutProjection($current, $method, $attempt);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function retryPayment(DirectBookingPropertySetting $setting, DirectBookingOrder $order, array $data, string $retryIdentity): array
    {
        $this->assertReady($setting);
        if (! in_array($order->state, [DirectBookingOrderState::PaymentFailed, DirectBookingOrderState::EvidenceRejected], true)) {
            throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'This payment cannot be retried from its current state.');
        }
        if ($order->state === DirectBookingOrderState::EvidenceRejected) {
            $transition = DB::transaction(function () use ($order, $data, $retryIdentity) {
                $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
                $reservation = $locked->reservation()->lockForUpdate()->firstOrFail();
                $this->assertLiveHold($locked, $reservation);

                return $this->states->transition(
                    $locked,
                    DirectBookingOrderState::AwaitingManualPayment,
                    DirectBookingTransitionAuthority::PaymentOrchestrator,
                    (int) $data['expected_state_version'],
                    'retry-manual:'.$retryIdentity,
                );
            }, 3);

            return $this->checkoutProjection($transition->order, DirectBookingPaymentMethod::ManualBankTransfer, null);
        }

        $capability = DirectBookingPaymentCapability::query()
            ->where('property_id', $setting->property_id)->where('currency', $order->currency)
            ->where('method', DirectBookingPaymentMethod::HostedCheckout)->where('is_enabled', true)
            ->with('providerConnection')->first();
        if ($capability?->providerConnection === null) {
            throw new DirectBookingContractException(DirectBookingErrorCode::BookingUnavailable, 'Hosted checkout is temporarily unavailable.', 503);
        }
        $issued = $this->issuePaymentRequest->handle($order, (int) $data['expected_state_version'], 'retry-request:'.$retryIdentity);
        // Remote checkout creation stays outside the replacement transaction.
        $attempt = $this->providerCheckout->handle($issued['request'], $capability->providerConnection);

        return $this->checkoutProjection($order->fresh(), DirectBookingPaymentMethod::HostedCheckout, $attempt);
    }

    /** @return array<string, mixed> */
    public function manualEvidence(
        DirectBookingPropertySetting $setting,
        DirectBookingOrder $order,
        int $expectedVersion,
        UploadedFile $upload,
        string $retryIdentity,
    ): array {
        $this->assertReady($setting);
        if ($order->state !== DirectBookingOrderState::AwaitingManualPayment || $order->state_version !== $expectedVersion) {
            throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'Manual evidence is not accepted from the current booking state.');
        }
        $this->evidenceScanner->assertSafe($upload);
        $path = $upload->getRealPath();
        $mime = is_string($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        $size = is_string($path) ? filesize($path) : false;
        $sha = is_string($path) ? hash_file('sha256', $path) : false;
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw ValidationException::withMessages(['evidence' => 'The evidence content type is unsupported.']),
        };
        if (! is_int($size) || ! is_string($sha)) {
            throw ValidationException::withMessages(['evidence' => 'The evidence file is invalid.']);
        }
        $storagePath = Storage::disk('local')->putFileAs(
            "guest-payment-evidence/{$order->tenant_id}/{$order->reservation_id}",
            $upload,
            Str::uuid().'.'.$extension,
        );
        if (! is_string($storagePath)) {
            throw new DirectBookingContractException(DirectBookingErrorCode::BookingUnavailable, 'Evidence storage is temporarily unavailable.', 503);
        }

        try {
            return DB::transaction(function () use ($order, $expectedVersion, $retryIdentity, $mime, $size, $sha, $storagePath, $extension): array {
                $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
                $reservation = $locked->reservation()->lockForUpdate()->firstOrFail();
                $this->assertLiveHold($locked, $reservation);
                $deposit = Deposit::query()->where('reservation_id', $reservation->id)
                    ->where('status', DepositStatus::Due)->orderBy('due_at')->lockForUpdate()->firstOrFail();
                $evidence = GuestPaymentEvidence::query()->create([
                    'reservation_id' => $reservation->id,
                    'guest_id' => $reservation->primary_guest_id,
                    'file_name' => 'payment-evidence.'.$extension,
                    'original_name' => 'payment-evidence.'.$extension,
                    'content_type' => $mime,
                    'detected_mime' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $sha,
                    'storage_path' => $storagePath,
                    'disk' => 'local',
                    'storage_key' => $storagePath,
                    'status' => 'review_pending',
                    'amount_minor' => $deposit->amount_minor,
                    'currency' => $deposit->currency,
                    'scan_status' => 'accepted',
                    'scan_state' => 'accepted',
                    'submitted_at' => now(),
                    'scanned_at' => now(),
                ]);
                $transition = $this->states->transition(
                    $locked,
                    DirectBookingOrderState::EvidencePending,
                    DirectBookingTransitionAuthority::GuestEvidence,
                    $expectedVersion,
                    'evidence:'.$retryIdentity,
                    ['reason_code' => 'private_evidence_accepted'],
                );

                return $this->statusProjection($transition->order);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storagePath);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function recover(DirectBookingPropertySetting $setting, string $reference, string $recoveryToken, int $expectedVersion, string $retryIdentity): array
    {
        $this->assertReady($setting);

        return DB::transaction(function () use ($setting, $reference, $recoveryToken, $expectedVersion, $retryIdentity): array {
            $recovered = $this->tokens->recover($recoveryToken, $setting->property_id);
            $order = $recovered['order'];
            if (! hash_equals($reference, $order->public_reference) || $order->state !== DirectBookingOrderState::Expired) {
                throw new AuthenticationException;
            }
            $transition = $this->states->transition(
                $order,
                DirectBookingOrderState::Started,
                DirectBookingTransitionAuthority::Recovery,
                $expectedVersion,
                'recover:'.$retryIdentity,
                ['reason_code' => 'session_recovered_for_repricing'],
            );

            return [
                'order_reference' => $order->public_reference,
                'session_token' => $recovered['token'],
                'recovery_token' => $recovered['recovery_token'],
                'state' => $transition->order->state->value,
                'state_version' => $transition->order->state_version,
                'locale' => $order->locale,
                'currency' => $order->currency,
                'session_expires_at' => $transition->order->session_expires_at->toIso8601String(),
                'recovery_expires_at' => $transition->order->recovery_expires_at->toIso8601String(),
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function confirmation(DirectBookingPropertySetting $setting, DirectBookingOrder $order): array
    {
        $this->assertReady($setting);
        if ($order->state !== DirectBookingOrderState::Confirmed || $order->reservation_id === null) {
            throw new AuthenticationException;
        }
        $reservation = $order->reservation()->firstOrFail();

        return [
            'order_reference' => $order->public_reference,
            'confirmation_number' => $reservation->confirmation_number,
            'state' => 'confirmed',
            'arrival_date' => $reservation->starts_at->setTimezone($setting->property->timezone)->toDateString(),
            'departure_date' => $reservation->ends_at->setTimezone($setting->property->timezone)->toDateString(),
            'timezone' => $setting->property->timezone,
            'total' => ['amount_minor' => $reservation->total_minor, 'currency' => $reservation->currency],
        ];
    }

    public function resolveOrder(DirectBookingPropertySetting $setting, string $reference, ?string $bearer): DirectBookingOrder
    {
        try {
            $order = $this->tokens->resolve((string) $bearer, $setting->property_id);
        } catch (AuthenticationException) {
            throw new AuthenticationException;
        }
        if (! hash_equals($reference, $order->public_reference)) {
            throw new AuthenticationException;
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private function quoteProjection(DirectBookingOrder $order, BookingQuote $quote): array
    {
        $deposit = match ($quote->deposit_policy_snapshot['requirement_type'] ?? 'percentage') {
            'fixed' => min($quote->total_minor, (int) ($quote->deposit_policy_snapshot['fixed_amount_minor'] ?? 0)),
            default => intdiv(($quote->total_minor * (int) ($quote->deposit_policy_snapshot['percentage_basis_points'] ?? 5000)) + 9999, 10000),
        };
        $policies = DirectBookingPublication::query()->where('property_id', $order->property_id)
            ->whereNull('public_item_id')->where('locale', $order->locale)
            ->whereIn('kind', ['terms', 'privacy', 'cancellation', 'no_show', 'marketing_consent'])
            ->where('state', DirectBookingPublicationState::Published)->orderBy('kind')->get();
        $timezone = (string) (Property::query()->whereKey($order->property_id)->value('timezone') ?: 'UTC');

        return [
            'order_reference' => $order->public_reference,
            'state' => 'quoted',
            'state_version' => $order->state_version,
            'quote_expires_at' => $quote->expires_at->toIso8601String(),
            'arrival_date' => $quote->starts_at->setTimezone($timezone)->toDateString(),
            'departure_date' => $quote->ends_at->setTimezone($timezone)->toDateString(),
            'timezone' => $timezone,
            'total' => ['amount_minor' => $quote->total_minor, 'currency' => $quote->currency],
            'deposit' => ['amount_minor' => $deposit, 'currency' => $quote->currency],
            'lines' => $quote->lines->map(fn ($line): array => [
                'type' => $line->type,
                'description' => $line->description,
                'service_on' => $line->service_on?->toDateString(),
                'amount' => ['amount_minor' => $line->gross_amount_minor, 'currency' => $quote->currency],
            ])->values()->all(),
            'policies' => $policies->map(fn ($policy): array => [
                'kind' => $policy->kind->value,
                'version' => $policy->version,
                'checksum' => $policy->checksum,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function statusProjection(DirectBookingOrder $order): array
    {
        $capabilities = DirectBookingPaymentCapability::query()
            ->where('property_id', $order->property_id)->where('currency', $order->currency)->where('is_enabled', true)
            ->orderBy('method')->get()->map(fn ($capability): array => [
                'method' => $capability->method->value,
                'currency' => $capability->currency,
            ])->values()->all();
        $actions = match ($order->state) {
            DirectBookingOrderState::Started => ['quote'],
            DirectBookingOrderState::Quoted => ['hold'],
            DirectBookingOrderState::Held => ['checkout'],
            DirectBookingOrderState::AwaitingManualPayment => ['submit_manual_evidence'],
            DirectBookingOrderState::Confirmed => ['view_confirmation'],
            DirectBookingOrderState::Expired => ['recover'],
            DirectBookingOrderState::PaymentFailed => ['retry_payment'],
            DirectBookingOrderState::EvidenceRejected => ['retry_payment', 'contact_property'],
            default => ['contact_property'],
        };

        return [
            'order_reference' => $order->public_reference,
            'state' => $order->state->value,
            'state_version' => $order->state_version,
            'session_expires_at' => $order->session_expires_at->toIso8601String(),
            'quote_expires_at' => $order->quote_expires_at?->toIso8601String(),
            'hold_expires_at' => $order->hold_expires_at?->toIso8601String(),
            'checkout_expires_at' => $order->checkout_expires_at?->toIso8601String(),
            'payment_capabilities' => $capabilities,
            'actions' => $actions,
            'safe_failure_code' => $order->safe_failure_code?->value,
        ];
    }

    /** @return array<string, mixed> */
    private function checkoutProjection(DirectBookingOrder $order, DirectBookingPaymentMethod $method, ?PaymentAttempt $attempt): array
    {
        return [
            'order_reference' => $order->public_reference,
            'state' => $order->state->value,
            'state_version' => $order->state_version,
            'method' => $method->value,
            'checkout_url' => $attempt?->hosted_checkout_url,
            'hold_expires_at' => $order->hold_expires_at->toIso8601String(),
            'checkout_expires_at' => ($order->checkout_expires_at ?? $order->hold_expires_at)->toIso8601String(),
        ];
    }

    private function assertReady(DirectBookingPropertySetting $setting): void
    {
        if (! $this->readiness->evaluate($setting)->ready) {
            throw new DirectBookingContractException(DirectBookingErrorCode::BookingUnavailable, 'Direct booking is temporarily unavailable.', 503);
        }
    }

    private function assertLocaleCurrency(DirectBookingPropertySetting $setting, string $locale, ?string $currency): void
    {
        if (! in_array($locale, $setting->supported_locales, true)
            || ($currency !== null && ! in_array(strtoupper($currency), $setting->supported_currencies, true))) {
            throw ValidationException::withMessages(['locale' => 'The requested locale or currency is unsupported.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function verifyBot(DirectBookingPropertySetting $setting, array $data, Request $request, string $action, string $retryIdentity): void
    {
        if (! $setting->bot_verification_required) {
            return;
        }
        $result = $this->botVerifier->verify((string) $data['turnstile_token'], $request->ip(), $action, $retryIdentity);
        if (! $result->valid) {
            throw new DirectBookingContractException(DirectBookingErrorCode::BotRejected, 'Bot verification failed.', 403);
        }
    }

    private function localDate(DirectBookingPropertySetting $setting, string $date): string
    {
        return $date.' 00:00:00 '.$setting->property->timezone;
    }

    private function assertLiveHold(DirectBookingOrder $order, Reservation $reservation): void
    {
        if ($reservation->status !== ReservationStatus::Hold
            || $reservation->hold_expires_at?->isFuture() !== true
            || $order->hold_expires_at?->isFuture() !== true
            || ! $reservation->hold_expires_at->equalTo($order->hold_expires_at)) {
            throw new DirectBookingContractException(DirectBookingErrorCode::HoldExpired, 'The authoritative reservation hold is missing or expired.');
        }
    }

    private function provisionProgramRequirements(Reservation $reservation): void
    {
        $reservation->loadMissing(['program.requirements.category', 'allocations']);
        $program = $reservation->program;
        if ($program === null) {
            return;
        }
        $partySize = max(1, $reservation->adults + $reservation->children);
        foreach ($program->requirements as $requirement) {
            $required = $requirement->quantityForParty($partySize);
            $existing = $reservation->allocations->firstWhere('requested_category_id', $requirement->resource_category_id);
            $hasTraits = ($requirement->capabilities ?? []) !== [] || ($requirement->languages ?? []) !== [];
            if ($existing !== null && ! $hasTraits) {
                if ($existing->quantity < $required) {
                    $existing->forceFill(['quantity' => $required])->save();
                    $this->inventory->assertAvailable($existing);
                }

                continue;
            }
            if ($existing !== null) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Unavailable, 'The selected program cannot be allocated through public booking.');
            }

            $resourceId = null;
            if ($hasTraits) {
                $resourceId = $this->resourceSuggestions->suggest(
                    $requirement->category,
                    $reservation->starts_at,
                    $reservation->ends_at,
                    $required,
                    $requirement->capabilities ?? [],
                    $requirement->languages ?? [],
                    $reservation->property_id,
                )->first()['id'] ?? null;
                if ($resourceId === null) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Unavailable, 'The selected program is unavailable.');
                }
            }
            $allocation = Allocation::query()->create([
                'reservation_id' => $reservation->id,
                'requested_category_id' => $hasTraits ? null : $requirement->resource_category_id,
                'resource_id' => $resourceId,
                'status' => AllocationStatus::Tentative,
                'starts_at' => $reservation->starts_at,
                'ends_at' => $reservation->ends_at,
                'quantity' => $required,
            ]);
            $this->inventory->assertAvailable($allocation);
        }
    }

    private function items(DirectBookingPropertySetting $setting)
    {
        return DirectBookingPublicItem::query()->where('property_id', $setting->property_id)
            ->where('is_enabled', true)->with('program')->get();
    }

    private function item($items, ?string $key, string $kind): ?DirectBookingPublicItem
    {
        if ($key === null) {
            return null;
        }
        $item = $items->first(fn (DirectBookingPublicItem $item): bool => $item->kind === $kind && hash_equals($item->public_key, $key));
        if (! $item instanceof DirectBookingPublicItem) {
            throw ValidationException::withMessages(["{$kind}_key" => 'The selected public option is unavailable.']);
        }

        return $item;
    }

    /** @param array<string, mixed> $guest @return array<string, string|null> */
    private function validatedGuest(array $guest): array
    {
        $clean = [];
        foreach (['first_name', 'last_name', 'email', 'phone'] as $field) {
            $value = isset($guest[$field]) ? trim((string) $guest[$field]) : '';
            if ($value !== '' && preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
                throw ValidationException::withMessages([$field => 'Control characters are not allowed.']);
            }
            $clean[$field] = $value === '' ? null : $value;
        }
        $clean['first_name'] ??= '';
        $clean['language'] = null;

        return $clean;
    }

    /** @return array{string, int} */
    private function quotedDeposit(BookingQuote $quote): array
    {
        $policy = $quote->deposit_policy_snapshot ?? [];
        $schedule = $policy === [] ? 'deposit_50' : 'deposit';
        $amount = match ($policy['requirement_type'] ?? 'percentage') {
            'fixed' => min($quote->total_minor, (int) ($policy['fixed_amount_minor'] ?? 0)),
            default => intdiv(($quote->total_minor * (int) ($policy['percentage_basis_points'] ?? 5000)) + 9999, 10000),
        };

        return [$schedule, $amount];
    }
}
