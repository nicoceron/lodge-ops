<?php

namespace App\Services\Payments;

use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Enums\ProviderEventState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessMercadoPagoOrderEvent
{
    private const PROCESSING_LEASE_SECONDS = 90;

    public function __construct(
        private readonly InPersonPaymentGatewayFactory $gateways,
        private readonly ApplyMercadoPagoOrder $orders,
    ) {}

    public function handle(ProviderEvent $event): ProviderEvent
    {
        [$claimed, $shouldProcess] = DB::transaction(function () use ($event): array {
            $locked = ProviderEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (in_array($locked->processing_state, [ProviderEventState::Processed, ProviderEventState::Duplicate, ProviderEventState::Mismatched], true)) {
                return [$locked, false];
            }
            $stale = $locked->processing_state === ProviderEventState::Processing
                && $locked->updated_at->lte(now()->subSeconds(self::PROCESSING_LEASE_SECONDS));
            if ($locked->processing_state === ProviderEventState::Processing && ! $stale) {
                return [$locked, false];
            }
            $locked->update([
                'processing_state' => ProviderEventState::Processing,
                'attempt_count' => $locked->attempt_count + 1,
                'last_error' => $stale ? 'Reclaimed after the previous Orders processing lease expired.' : null,
            ]);

            return [$locked, true];
        }, 3);
        if (! $shouldProcess) {
            return $claimed;
        }

        try {
            if ($claimed->topic !== 'order' || $claimed->event_type !== 'order' || ! str_starts_with((string) $claimed->action, 'order.')) {
                return $this->mismatch($claimed, 'The event is not a Mercado Pago Orders topic/action.');
            }
            $remote = $this->gateways->for($claimed->integrationConnection)->fetchOrder((string) $claimed->resource_id);
            $attempt = PaymentAttempt::query()
                ->where('integration_connection_id', $claimed->integration_connection_id)
                ->where('provider', $claimed->provider)
                ->where('environment', $claimed->environment)
                ->where('provider_account', $claimed->provider_account)
                ->where(fn ($query) => $query->where('provider_order_id', $remote->id)->orWhere('external_reference', $remote->externalReference))
                ->first();
            if ($attempt === null || $claimed->resource_id !== $remote->id || $claimed->provider_account !== $remote->providerAccount) {
                return $this->mismatch($claimed, 'No in-person payment attempt matches the authoritative Orders resource/account.');
            }
            $result = $this->orders->handle($attempt, $remote);
            if ($result->state->value === 'mismatched') {
                return $this->mismatch($claimed, (string) $result->last_error);
            }
            $claimed->update(['processing_state' => ProviderEventState::Processed, 'processed_at' => now(), 'last_error' => null]);

            return $claimed->fresh();
        } catch (DomainException $exception) {
            return $this->mismatch($claimed, $exception->getMessage());
        } catch (Throwable $exception) {
            $claimed->update(['processing_state' => ProviderEventState::Failed, 'last_error' => Str::limit($exception->getMessage(), 500)]);
            throw $exception;
        }
    }

    private function mismatch(ProviderEvent $event, string $reason): ProviderEvent
    {
        $event->update(['processing_state' => ProviderEventState::Mismatched, 'processed_at' => now(), 'last_error' => Str::limit($reason, 500)]);

        return $event->fresh();
    }
}
