<?php

namespace App\Services\Payments;

use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Data\Payments\ProviderOrderMutation;
use App\Enums\PaymentAttemptState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\PaymentAttempt;
use App\Services\Documents\CanonicalJson;
use Illuminate\Support\Str;

final class CancelInPersonOrder
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly InPersonPaymentGatewayFactory $gateways,
        private readonly ApplyMercadoPagoOrder $orders,
        private readonly CanonicalJson $canonical,
    ) {}

    public function handle(PaymentAttempt $attempt, string $commandKey): PaymentAttempt
    {
        if ($attempt->provider_order_id === null) {
            throw new DomainException('Cannot cancel until the provider order identity is authoritatively recovered.');
        }
        /** @var PaymentAttempt $prepared */
        $prepared = $this->commands->run($attempt->tenant_id, 'in_person_order.cancel', $commandKey, ['attempt_id' => $attempt->id], function () use ($attempt): PaymentAttempt {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->cancel_idempotency_key === null) {
                $body = ['provider_order_id' => $locked->provider_order_id];
                $locked->update([
                    'cancel_idempotency_key' => (string) Str::uuid(),
                    'cancel_request_checksum' => $this->canonical->checksum($body),
                    'cancel_requested_at' => now(),
                ]);
            }

            return $locked;
        });
        $gateway = $this->gateways->for($prepared->integrationConnection);
        $current = $gateway->fetchOrder($prepared->provider_order_id);
        $prepared = $this->orders->handle($prepared, $current);
        if ($current->status === 'at_terminal') {
            $prepared->update([
                'state' => PaymentAttemptState::ActionRequired,
                'action_required_at' => now(),
                'last_error' => 'Point order is at_terminal and must be canceled on the physical terminal before replacement.',
            ]);

            return $prepared->fresh();
        }
        if ($current->status !== 'created') {
            return $prepared->fresh();
        }
        try {
            $remote = $gateway->cancelOrder(new ProviderOrderMutation(
                $prepared->provider_order_id,
                $prepared->cancel_idempotency_key,
                $prepared->cancel_request_checksum,
            ));

            return $this->orders->handle($prepared, $remote);
        } catch (\Throwable $exception) {
            try {
                $recovered = $gateway->fetchOrder($prepared->provider_order_id);
                $result = $this->orders->handle($prepared, $recovered);
                if ($recovered->status !== 'created') {
                    return $result;
                }
            } catch (\Throwable) {
                // Preserve the original ambiguous mutation result below.
            }
            $prepared->update([
                'state' => PaymentAttemptState::ActionRequired,
                'action_required_at' => now(),
                'last_error' => Str::limit('Cancel result is uncertain; authoritative lookup is still created/unavailable. Do not replace. '.$exception->getMessage(), 500),
            ]);
            throw $exception;
        }
    }
}
