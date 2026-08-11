<?php

namespace App\Services\Automation;

use App\Enums\DepositStatus;
use App\Models\AutomationRule;
use App\Models\Communication;
use App\Models\Deposit;
use App\Models\OperationalTask;
use App\Models\Outbox;
use App\Models\Reservation;
use App\Services\GuestPortalTokenService;
use DomainException;

class AutomationActionExecutor
{
    public function __construct(
        private readonly AutomationTemplateRenderer $renderer,
        private readonly OutboxRecorder $outbox,
        private readonly GuestPortalTokenService $guestPortalTokens,
        private readonly InternalStaffNotificationService $internalStaffNotifications,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     */
    public function execute(
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        array $action,
        array $context,
    ): void {
        $type = $action['type'] ?? null;

        match ($type) {
            'task', 'create_task' => $this->createTask($message, $rule, $actionIndex, $action, $context),
            'communication', 'queue_communication' => $this->queueCommunication($message, $rule, $actionIndex, $action, $context),
            'deposit_reminder', 'send_deposit_reminder' => $this->queueDepositReminders($message, $rule, $actionIndex, $action, $context),
            'guest_portal_invitation', 'queue_guest_portal_invitation' => $this->queueGuestPortalInvitation($message, $rule, $actionIndex, $action, $context),
            'internal_notify' => $this->internalStaffNotifications->deliver($message, $rule, $actionIndex, $action, $context),
            default => throw new DomainException("Unsupported automation action [{$type}]."),
        };
    }

    /** @param array<string, mixed> $action @param array<string, mixed> $context */
    private function createTask(
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        array $action,
        array $context,
    ): void {
        $propertyId = data_get($context, 'reservation.property_id') ?? ($action['property_id'] ?? null);

        if (! is_string($propertyId) || $propertyId === '') {
            throw new DomainException('Task automations require a reservation or property_id.');
        }

        $automationKey = $this->automationKey($message, $rule, $actionIndex, 'task');

        OperationalTask::query()->firstOrCreate(
            ['automation_key' => $automationKey],
            [
                'property_id' => $propertyId,
                'reservation_id' => data_get($context, 'reservation.id'),
                'title' => $this->renderer->render($action['title'] ?? 'Reservation follow-up', $context),
                'description' => $this->renderer->render($action['description'] ?? null, $context),
                'status' => 'todo',
                'priority' => $action['priority'] ?? 'normal',
                'due_at' => now()->addMinutes(max(0, (int) ($action['due_in_minutes'] ?? 0))),
                'metadata' => $this->metadata($message, $rule, $actionIndex, 'task'),
            ],
        );
    }

    /** @param array<string, mixed> $action @param array<string, mixed> $context */
    private function queueCommunication(
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        array $action,
        array $context,
    ): void {
        $automationKey = $this->automationKey($message, $rule, $actionIndex, 'communication');

        $communication = Communication::query()->firstOrCreate(
            ['automation_key' => $automationKey],
            [
                'guest_id' => data_get($context, 'reservation.primary_guest_id'),
                'reservation_id' => data_get($context, 'reservation.id'),
                'channel' => $action['channel'] ?? 'email',
                'direction' => 'outbound',
                'status' => 'queued',
                'subject' => $this->renderer->render($action['subject'] ?? null, $context),
                'body' => $this->renderer->render($action['body'] ?? 'A reservation update is ready.', $context),
                'metadata' => $this->metadata($message, $rule, $actionIndex, 'communication'),
            ],
        );

        if ($communication->wasRecentlyCreated) {
            $this->outbox->record('communication', $communication->id, 'communication.queued', [
                'communication_id' => $communication->id,
                'channel' => $communication->channel,
            ]);
        }
    }

    /** @param array<string, mixed> $action @param array<string, mixed> $context */
    private function queueDepositReminders(
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        array $action,
        array $context,
    ): void {
        $reservationId = data_get($context, 'reservation.id');

        if (! is_string($reservationId) || $reservationId === '') {
            throw new DomainException('Deposit reminder automations require a reservation.');
        }

        $deposits = Deposit::query()
            ->where('reservation_id', $reservationId)
            ->where('status', DepositStatus::Due)
            ->when(
                isset($action['due_within_hours']),
                fn ($query) => $query->where('due_at', '<=', now()->addHours((int) $action['due_within_hours'])),
            )
            ->get();

        foreach ($deposits as $deposit) {
            $depositContext = [...$context, 'deposit' => [
                'id' => $deposit->id,
                'amount_minor' => $deposit->amount_minor,
                'currency' => $deposit->currency,
                'due_at' => $deposit->due_at?->toIso8601String(),
            ]];
            $automationKey = $this->automationKey($message, $rule, $actionIndex, 'deposit-reminder-'.$deposit->id);

            $communication = Communication::query()->firstOrCreate(
                ['automation_key' => $automationKey],
                [
                    'guest_id' => data_get($context, 'reservation.primary_guest_id'),
                    'reservation_id' => $reservationId,
                    'channel' => $action['channel'] ?? 'email',
                    'direction' => 'outbound',
                    'status' => 'queued',
                    'subject' => $this->renderer->render($action['subject'] ?? 'Deposit reminder', $depositContext),
                    'body' => $this->renderer->render(
                        $action['body'] ?? 'Your deposit of {{deposit.amount_minor}} {{deposit.currency}} is due.',
                        $depositContext,
                    ),
                    'metadata' => [
                        ...$this->metadata($message, $rule, $actionIndex, 'deposit_reminder'),
                        'deposit_id' => $deposit->id,
                    ],
                ],
            );

            if ($communication->wasRecentlyCreated) {
                $this->outbox->record('communication', $communication->id, 'communication.queued', [
                    'communication_id' => $communication->id,
                    'channel' => $communication->channel,
                ]);
            }
        }
    }

    private function automationKey(Outbox $message, AutomationRule $rule, int $actionIndex, string $suffix): string
    {
        return implode(':', [$message->id, $rule->id, $actionIndex, $suffix]);
    }

    /** @param array<string, mixed> $action @param array<string, mixed> $context */
    private function queueGuestPortalInvitation(
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        array $action,
        array $context,
    ): void {
        $reservationId = data_get($context, 'reservation.id');
        if (! is_string($reservationId) || $reservationId === '') {
            throw new DomainException('Guest portal invitations require a reservation.');
        }

        $automationKey = $this->automationKey($message, $rule, $actionIndex, 'guest-portal-invitation');
        if (Communication::query()->where('automation_key', $automationKey)->exists()) {
            return;
        }

        $reservation = Reservation::query()->with('primaryGuest')->findOrFail($reservationId);
        if ($reservation->primaryGuest === null || ! $reservation->primaryGuest->email) {
            throw new DomainException('Guest portal invitations require a primary guest email address.');
        }

        $access = $this->guestPortalTokens->issue($reservation, $reservation->primaryGuest);
        $url = rtrim((string) config('app.url'), '/')
            .'/guest/access/'.rawurlencode($access['token']);
        $invitationContext = [...$context, 'guest_portal' => ['url' => $url]];
        $communication = Communication::query()->create([
            'automation_key' => $automationKey,
            'guest_id' => $reservation->primary_guest_id,
            'reservation_id' => $reservation->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'status' => 'queued',
            'subject' => $this->renderer->render($action['subject'] ?? 'Your private lodge stay link', $invitationContext),
            'body' => $this->renderer->render(
                $action['body'] ?? 'Open your secure reservation center: {{guest_portal.url}}',
                $invitationContext,
            ),
            'metadata' => [
                ...$this->metadata($message, $rule, $actionIndex, 'guest_portal_invitation'),
                'guest_portal_access_id' => $access['access']->id,
                'purpose' => $action['purpose'] ?? 'stay',
            ],
        ]);
        $this->outbox->record('communication', $communication->id, 'communication.queued', [
            'communication_id' => $communication->id,
            'channel' => 'email',
        ]);
    }

    /** @return array<string, mixed> */
    private function metadata(Outbox $message, AutomationRule $rule, int $actionIndex, string $type): array
    {
        return [
            'automation_rule_id' => $rule->id,
            'outbox_id' => $message->id,
            'action_index' => $actionIndex,
            'action_type' => $type,
        ];
    }
}
