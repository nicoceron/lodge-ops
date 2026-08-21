<?php

namespace App\Services\Payments;

use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Data\Payments\PointOrderRequest;
use App\Data\Payments\QrOrderRequest;
use App\Enums\DepositStatus;
use App\Enums\PaymentAttemptState;
use App\Enums\PaymentChannel;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestState;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Integrations\Payments\MercadoPago\MercadoPagoTransportException;
use App\Models\Deposit;
use App\Models\PaymentAttempt;
use App\Models\PaymentRequest;
use App\Models\PaymentTerminal;
use App\Models\ProviderPosLocation;
use App\Models\Reservation;
use App\Services\Documents\CanonicalJson;
use App\Services\FolioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InitiateInPersonPayment
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly InPersonPaymentGatewayFactory $gateways,
        private readonly InPersonPaymentConnectionResolver $connections,
        private readonly ApplyMercadoPagoOrder $orders,
        private readonly FolioService $folio,
        private readonly CanonicalJson $canonical,
    ) {}

    public function handle(
        Reservation $reservation,
        PaymentChannel $channel,
        string $targetId,
        PaymentRequestPurpose $purpose,
        ?string $depositId,
        ?int $authorizedAmountMinor,
        int $actorId,
        string $commandKey,
    ): PaymentAttempt {
        if (! in_array($channel, [PaymentChannel::IntegratedTerminal, PaymentChannel::Qr], true)) {
            throw new DomainException('Only Point or QR may use the in-person Orders command.');
        }
        $payload = compact('channel', 'targetId', 'purpose', 'depositId', 'authorizedAmountMinor');
        /** @var PaymentAttempt $attempt */
        $attempt = $this->commands->run((string) $reservation->getAttribute('tenant_id'), 'in_person_order.create', $commandKey, $payload, function () use (
            $reservation, $channel, $targetId, $purpose, $depositId, $authorizedAmountMinor, $actorId
        ): PaymentAttempt {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($locked->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
                throw new DomainException('Point and QR requests require a confirmed or checked-in reservation.');
            }
            $outstanding = max(0, $this->folio->summary($locked)['balance_minor']);
            $deposit = $depositId === null ? null : Deposit::query()->lockForUpdate()->findOrFail($depositId);
            if ($deposit !== null && ($deposit->reservation_id !== $locked->id || $deposit->status !== DepositStatus::Due || $deposit->currency !== $locked->currency)) {
                throw new DomainException('The selected deposit is not due in this reservation currency.');
            }
            if ($purpose === PaymentRequestPurpose::Deposit && $deposit === null) {
                throw new DomainException('A due deposit is required for a deposit payment request.');
            }
            $amount = match ($purpose) {
                PaymentRequestPurpose::Deposit => $deposit->amount_minor,
                PaymentRequestPurpose::Balance, PaymentRequestPurpose::FullOutstanding => $outstanding,
                PaymentRequestPurpose::AuthorizedPartial => $authorizedAmountMinor ?? 0,
            };
            if ($amount <= 0 || $amount > $outstanding) {
                throw new DomainException('The in-person payment amount must be positive and no greater than the authoritative outstanding balance.');
            }
            $target = $channel === PaymentChannel::IntegratedTerminal
                ? PaymentTerminal::query()->lockForUpdate()->findOrFail($targetId)
                : ProviderPosLocation::query()->lockForUpdate()->findOrFail($targetId);
            if ($target->property_id !== $locked->property_id) {
                throw new DomainException('The terminal or POS is outside this property.');
            }
            $connection = $this->connections->forProperty(
                (string) $locked->getAttribute('tenant_id'),
                $locked->property_id,
                $channel,
                $locked->currency,
                $target->integration_connection_id,
            );
            if ($target->property_id !== $locked->property_id || $target->integration_connection_id !== $connection->id
                || $target->provider_account !== $connection->external_account_id || $target->environment !== $connection->environment
                || ! $target->is_enabled || $target->replaced_by_id !== null) {
                throw new DomainException('The terminal or POS is disabled, replaced, or outside this property/account/environment.');
            }
            if ($target instanceof PaymentTerminal && strtoupper($target->operating_mode) !== 'PDV') {
                throw new DomainException('An integrated Point terminal must be confirmed in PDV operating mode.');
            }
            $activeStates = array_map(fn (PaymentAttemptState $state): string => $state->value, array_filter(
                PaymentAttemptState::cases(), fn (PaymentAttemptState $state): bool => $state->reusable()
            ));
            $active = PaymentAttempt::query()
                ->where($target instanceof PaymentTerminal ? 'payment_terminal_id' : 'provider_pos_location_id', $target->id)
                ->whereIn('state', $activeStates)->lockForUpdate()->first();
            if ($active !== null) {
                if ($active->reservation_id === $locked->id && $active->charge_amount_minor === $amount && $active->channel === $channel->value) {
                    return $active;
                }
                throw new DomainException('The selected terminal or POS already has an unresolved active order.');
            }
            $snapshot = [
                'reservation_revision' => $locked->revision,
                'outstanding_minor' => $outstanding,
                'deposit_id' => $deposit?->id,
                'target_type' => $target instanceof PaymentTerminal ? 'point_terminal' : 'qr_pos',
                'target_id' => $target->id,
                'calculated_at' => now()->toIso8601String(),
            ];
            $request = PaymentRequest::query()->create([
                'property_id' => $locked->property_id,
                'reservation_id' => $locked->id,
                'deposit_id' => $deposit?->id,
                'created_by' => $actorId,
                'public_id' => null,
                'access_token_hash' => null,
                'initiation_mode' => $channel === PaymentChannel::IntegratedTerminal ? 'staff_point' : 'staff_qr',
                'purpose' => $purpose,
                'state' => PaymentRequestState::Processing,
                'source_amount_minor' => $amount,
                'source_currency' => $locked->currency,
                'charge_currency' => $locked->currency,
                'calculation_snapshot' => $snapshot,
                'calculation_checksum' => $this->canonical->checksum($snapshot),
                'expires_at' => now()->addMinutes(15),
            ]);
            $providerIdempotency = (string) Str::uuid();
            $externalReference = (string) Str::uuid();
            $createBody = [
                'type' => $channel === PaymentChannel::IntegratedTerminal ? 'point' : 'qr',
                'external_reference' => $externalReference,
                'amount_minor' => $amount,
                'currency' => $locked->currency,
                'target' => $target instanceof PaymentTerminal ? $target->provider_terminal_id : $target->external_pos_id,
                'qr_mode' => $target instanceof ProviderPosLocation ? $target->qr_mode : null,
            ];

            return PaymentAttempt::query()->create([
                'property_id' => $locked->property_id,
                'reservation_id' => $locked->id,
                'payment_request_id' => $request->id,
                'deposit_id' => $deposit?->id,
                'integration_connection_id' => $connection->id,
                'payment_terminal_id' => $target instanceof PaymentTerminal ? $target->id : null,
                'provider_pos_location_id' => $target instanceof ProviderPosLocation ? $target->id : null,
                'provider' => 'mercado_pago',
                'environment' => $connection->environment,
                'provider_account' => $connection->external_account_id,
                'external_reference' => $externalReference,
                'idempotency_key' => $providerIdempotency,
                'create_request_checksum' => $this->canonical->checksum($createBody),
                'purpose' => $purpose->value,
                'channel' => $channel->value,
                'state' => PaymentAttemptState::Creating,
                'source_amount_minor' => $amount,
                'source_currency' => $locked->currency,
                'charge_amount_minor' => $amount,
                'charge_currency' => $locked->currency,
                'provider_order_type' => $channel === PaymentChannel::IntegratedTerminal ? 'point' : 'qr',
                'qr_mode' => $target instanceof ProviderPosLocation ? $target->qr_mode : null,
                'order_expires_at' => now()->addMinutes(15),
            ]);
        });

        if ($attempt->provider_order_id !== null) {
            return $attempt->fresh();
        }
        $gateway = $this->gateways->for($attempt->integrationConnection);
        $description = 'Inn '.str_replace('_', ' ', $attempt->purpose).' payment';
        try {
            if ($channel === PaymentChannel::IntegratedTerminal) {
                $terminal = PaymentTerminal::query()->findOrFail($attempt->payment_terminal_id);
                $remote = $gateway->createPointOrder(new PointOrderRequest(
                    $attempt->external_reference,
                    $attempt->idempotency_key,
                    $attempt->create_request_checksum,
                    $attempt->charge_amount_minor,
                    $attempt->charge_currency,
                    $description,
                    $terminal->provider_terminal_id,
                ));
            } else {
                $pos = ProviderPosLocation::query()->findOrFail($attempt->provider_pos_location_id);
                $remote = $gateway->createQrOrder(new QrOrderRequest(
                    $attempt->external_reference,
                    $attempt->idempotency_key,
                    $attempt->create_request_checksum,
                    $attempt->charge_amount_minor,
                    $attempt->charge_currency,
                    $description,
                    $pos->external_pos_id,
                    $pos->qr_mode,
                ));
            }

            return $this->orders->handle($attempt, $remote);
        } catch (\Throwable $exception) {
            if ($exception instanceof MercadoPagoTransportException
                && $exception->providerCode === 'already_queued_order_for_terminal'
                && $exception->providerResourceId !== null) {
                $remote = $gateway->fetchOrder($exception->providerResourceId);

                return $this->orders->handle($attempt, $remote);
            }
            $operatorAction = $exception instanceof MercadoPagoTransportException
                && in_array($exception->providerCode, ['already_queued_order_for_terminal', 'terminal_busy', 'terminal_offline'], true);
            DB::transaction(function () use ($attempt, $exception, $operatorAction): void {
                $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                $locked->update([
                    'state' => $operatorAction ? PaymentAttemptState::ActionRequired : PaymentAttemptState::Creating,
                    'action_required_at' => $operatorAction ? now() : null,
                    'error_count' => $locked->error_count + 1,
                    'last_error' => Str::limit($operatorAction
                        ? 'Provider or terminal action is required before retry. '.$exception->getMessage()
                        : 'Create result is uncertain; retrying this same request will safely resume the same provider operation. '.$exception->getMessage(), 500),
                ]);
            });
            if ($operatorAction) {
                return $attempt->fresh();
            }
            throw $exception;
        }
    }
}
