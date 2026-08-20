<?php

namespace App\Services\Automation;

use App\Jobs\SendCommunication;
use App\Models\AutomationRule;
use App\Models\Outbox;
use App\Models\Payment;
use App\Models\Proposal;
use App\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use DomainException;

class AutomationEngine
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AutomationConditionMatcher $matcher,
        private readonly AutomationActionExecutor $executor,
    ) {}

    public function handle(Outbox $message): void
    {
        if ($message->event_type === 'communication.queued') {
            $communicationId = $message->payload['communication_id'] ?? null;
            if (! is_string($communicationId) || $communicationId === '') {
                throw new DomainException('Communication delivery events require a communication_id.');
            }

            SendCommunication::dispatch($this->tenantContext->id(), $communicationId)->afterCommit();

            return;
        }

        $context = $this->context($message);

        AutomationRule::query()
            ->where('trigger', $message->event_type)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->each(function (AutomationRule $rule) use ($message, $context): void {
                if (! $this->matcher->matches($rule->conditions, $context)) {
                    return;
                }

                $actions = $this->normalizeActions($rule->actions);

                foreach ($actions as $index => $action) {
                    $this->executor->execute($message, $rule, $index, $action, $context);
                }

                $rule->forceFill(['last_ran_at' => now()])->save();
            });
    }

    /** @return array<string, mixed> */
    private function context(Outbox $message): array
    {
        $reservationId = $message->payload['reservation_id'] ?? null;
        $paymentId = $message->payload['payment_id'] ?? null;
        $proposalId = $message->payload['proposal_id'] ?? null;
        $reservation = is_string($reservationId)
            ? Reservation::query()->with('primaryGuest')->find($reservationId)
            : null;
        $payment = is_string($paymentId) ? Payment::query()->find($paymentId) : null;
        $proposal = is_string($proposalId) ? Proposal::query()->with('primaryGuest')->find($proposalId) : null;

        return [
            'event_type' => $message->event_type,
            'payload' => $message->payload,
            'tenant' => [
                'id' => $this->tenantContext->tenant()->id,
                'name' => $this->tenantContext->tenant()->name,
                'currency' => $this->tenantContext->tenant()->currency,
                'timezone' => $this->tenantContext->tenant()->timezone,
            ],
            'reservation' => $reservation?->toArray(),
            'payment' => $payment?->toArray(),
            'proposal' => $proposal?->toArray(),
        ];
    }

    /** @param array<mixed> $actions @return list<array<string, mixed>> */
    private function normalizeActions(array $actions): array
    {
        if (isset($actions['type'])) {
            $actions = [$actions];
        }

        foreach ($actions as $action) {
            if (! is_array($action)) {
                throw new DomainException('Automation actions must be objects.');
            }
        }

        return array_values($actions);
    }
}
