<?php

namespace App\Services\Automation;

use App\Enums\CommunicationPurpose;
use App\Enums\DepositStatus;
use App\Models\AutomationRule;
use App\Models\Communication;
use App\Models\Deposit;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\MessageTemplate;
use App\Models\OperationalTask;
use App\Models\Outbox;
use App\Models\Reservation;
use App\Services\GuestPortalTokenService;
use App\Services\MessageTemplateService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

class AutomationActionExecutor
{
    public function __construct(
        private readonly AutomationTemplateRenderer $renderer,
        private readonly OutboxRecorder $outbox,
        private readonly GuestPortalTokenService $guestPortalTokens,
        private readonly InternalStaffNotificationService $internalStaffNotifications,
        private readonly MessageTemplateService $messageTemplates,
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
            'queue_internal_communications' => $this->queueInternalCommunications($message, $rule, $actionIndex, $action, $context),
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
        $propertyId = $this->propertyId($context) ?? ($action['property_id'] ?? null);

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

        if ($this->queueConfiguredTemplate($automationKey, $action, $context, $message, $rule, $actionIndex, 'communication')) {
            return;
        }

        $guest = $this->guestForContext($context);
        $channel = (string) ($action['channel'] ?? 'email');
        $propertyId = $this->propertyId($context);
        if ($guest !== null && $this->messageTemplates->isSuppressed($guest, $channel, $propertyId)) {
            $this->recordSuppressed($automationKey, $guest, data_get($context, 'reservation.id'), $channel, $message, $rule, $actionIndex, 'communication', $this->purpose($action));

            return;
        }

        $communication = Communication::query()->firstOrCreate(
            ['automation_key' => $automationKey],
            [
                'property_id' => $propertyId,
                'guest_id' => $guest?->id,
                'reservation_id' => data_get($context, 'reservation.id'),
                'channel' => $channel,
                'direction' => 'outbound',
                'purpose' => $this->purpose($action)->value,
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

            if ($this->queueConfiguredTemplate($automationKey, $action, $depositContext, $message, $rule, $actionIndex, 'deposit_reminder')) {
                continue;
            }

            $guest = $this->guestForContext($context);
            $channel = (string) ($action['channel'] ?? 'email');
            if ($guest !== null && $this->messageTemplates->isSuppressed($guest, $channel, $this->propertyId($context))) {
                $this->recordSuppressed($automationKey, $guest, $reservationId, $channel, $message, $rule, $actionIndex, 'deposit_reminder', $this->purpose($action), [
                    'deposit_id' => $deposit->id,
                ]);

                continue;
            }

            $communication = Communication::query()->firstOrCreate(
                ['automation_key' => $automationKey],
                [
                    'property_id' => data_get($context, 'reservation.property_id'),
                    'guest_id' => data_get($context, 'reservation.primary_guest_id'),
                    'reservation_id' => $reservationId,
                    'channel' => $channel,
                    'direction' => 'outbound',
                    'purpose' => $this->purpose($action)->value,
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
        if ($this->messageTemplates->isSuppressed($reservation->primaryGuest, 'email', $reservation->property_id)) {
            $this->recordSuppressed(
                $automationKey,
                $reservation->primaryGuest,
                $reservation->id,
                'email',
                $message,
                $rule,
                $actionIndex,
                'guest_portal_invitation',
                $this->purpose($action),
            );

            return;
        }

        $access = $this->guestPortalTokens->issue($reservation, $reservation->primaryGuest);
        $url = rtrim((string) config('app.url'), '/')
            .'/guest/access/'.rawurlencode($access['token']);
        $invitationContext = [...$context, 'guest_portal' => ['url' => $url]];
        if ($this->queueConfiguredTemplate(
            $automationKey,
            $action,
            $invitationContext,
            $message,
            $rule,
            $actionIndex,
            'guest_portal_invitation',
            ['guest_portal_access_id' => $access['access']->id, 'purpose' => $action['purpose'] ?? 'stay'],
        )) {
            return;
        }
        $communication = Communication::query()->create([
            'property_id' => $reservation->property_id,
            'automation_key' => $automationKey,
            'guest_id' => $reservation->primary_guest_id,
            'reservation_id' => $reservation->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'purpose' => $this->purpose($action)->value,
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

    /** @param array<string, mixed> $action @param array<string, mixed> $context @param array<string, mixed> $extraMetadata */
    private function queueConfiguredTemplate(
        string $automationKey,
        array $action,
        array $context,
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        string $type,
        array $extraMetadata = [],
    ): bool {
        $templateKey = $action['template_key'] ?? null;
        if (! is_string($templateKey) || $templateKey === '') {
            return false;
        }

        $channel = (string) ($action['channel'] ?? 'email');
        $template = MessageTemplate::query()
            ->where('key', $templateKey)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->firstOrFail();
        $guest = $this->guestForContext($context);
        if ($guest === null) {
            throw new DomainException('Template automations require a guest recipient.');
        }
        $reservationId = data_get($context, 'reservation.id');
        $reservation = is_string($reservationId) ? Reservation::query()->find($reservationId) : null;

        try {
            $context = [...$context, 'communication_purpose' => $this->purpose($action)->value];
            $communication = $this->messageTemplates->queue(
                $template,
                $guest,
                (string) ($action['language'] ?? $guest->language ?? 'en'),
                $automationKey,
                $context,
                $reservation,
            );
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'Communication to this recipient is suppressed.') {
                throw $exception;
            }
            $this->recordSuppressed($automationKey, $guest, $reservation?->id, $channel, $message, $rule, $actionIndex, $type, $this->purpose($action), $extraMetadata);

            return true;
        }

        $communication->forceFill(['metadata' => [
            ...($communication->metadata ?? []),
            ...$this->metadata($message, $rule, $actionIndex, $type),
            ...$extraMetadata,
        ]])->save();

        return true;
    }

    /** @param array<string, mixed> $context */
    private function guestForContext(array $context): ?Guest
    {
        $guestId = data_get($context, 'reservation.primary_guest_id') ?? data_get($context, 'proposal.primary_guest_id');

        return is_string($guestId) ? Guest::query()->find($guestId) : null;
    }

    /** @param array<string, mixed> $context */
    private function propertyId(array $context): ?string
    {
        $propertyId = data_get($context, 'reservation.property_id')
            ?? data_get($context, 'proposal.property_id')
            ?? data_get($context, 'payload.property_id');

        return is_string($propertyId) && $propertyId !== '' ? $propertyId : null;
    }

    /** @param array<string, mixed> $action @param array<string, mixed> $context */
    private function queueInternalCommunications(
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        array $action,
        array $context,
    ): void {
        $propertyId = $this->propertyId($context);
        $roles = array_values(array_filter((array) ($action['roles'] ?? []), 'is_string'));
        $purpose = $this->purpose($action);
        if ($propertyId === null || $roles === [] || ! str_starts_with($purpose->value, 'internal_')) {
            throw new DomainException('Internal communications require property scope, roles, and an internal purpose.');
        }

        Membership::query()->with('user')->where('is_active', true)->whereIn('role', $roles)
            ->where(fn (Builder $query) => $query->whereNull('property_id')->orWhere('property_id', $propertyId))
            ->get()->each(function (Membership $membership) use ($message, $rule, $actionIndex, $action, $context, $propertyId, $purpose): void {
                $recipient = mb_strtolower(trim((string) $membership->user->email));
                if ($recipient === '') {
                    return;
                }
                $automationKey = $this->automationKey($message, $rule, $actionIndex, 'internal-'.$membership->user_id);
                $communication = Communication::query()->firstOrCreate(
                    ['automation_key' => $automationKey],
                    [
                        'property_id' => $propertyId,
                        'reservation_id' => data_get($context, 'reservation.id'),
                        'channel' => 'email',
                        'direction' => 'outbound',
                        'purpose' => $purpose->value,
                        'status' => 'queued',
                        'subject' => $this->renderer->render($action['subject'] ?? 'Lodge operations update', $context),
                        'body' => $this->renderer->render($action['body'] ?? 'A role-scoped operational update is ready.', $context),
                        'metadata' => [
                            ...$this->metadata($message, $rule, $actionIndex, 'internal_communication'),
                            'recipient' => $recipient,
                            'recipient_user_id' => $membership->user_id,
                            'recipient_role' => $membership->role->value,
                        ],
                    ],
                );
                if ($communication->wasRecentlyCreated) {
                    $this->outbox->record('communication', $communication->id, 'communication.queued', [
                        'communication_id' => $communication->id,
                        'channel' => 'email',
                    ]);
                }
            });
    }

    /** @param array<string, mixed> $extraMetadata */
    private function recordSuppressed(
        string $automationKey,
        Guest $guest,
        ?string $reservationId,
        string $channel,
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        string $type,
        CommunicationPurpose $purpose,
        array $extraMetadata = [],
    ): void {
        Communication::query()->firstOrCreate(
            ['automation_key' => $automationKey],
            [
                'guest_id' => $guest->id,
                'reservation_id' => $reservationId,
                'property_id' => $reservationId === null ? null : Reservation::query()->whereKey($reservationId)->value('property_id'),
                'channel' => $channel,
                'direction' => 'outbound',
                'purpose' => $purpose->value,
                'status' => 'suppressed',
                'subject' => null,
                'body' => '',
                'metadata' => [
                    ...$this->metadata($message, $rule, $actionIndex, $type),
                    ...$extraMetadata,
                    'suppressed_at' => now()->toIso8601String(),
                    'recipient_hash' => $this->messageTemplates->recipientHash(
                        (string) $this->messageTemplates->recipient($guest, $channel),
                    ),
                ],
            ],
        );
    }

    /** @param array<string, mixed> $action */
    private function purpose(array $action): CommunicationPurpose
    {
        $purpose = CommunicationPurpose::tryFrom((string) ($action['purpose'] ?? CommunicationPurpose::Transactional->value));
        if ($purpose === null) {
            throw new DomainException('Automation communication purpose is not an approved fixed purpose.');
        }

        return $purpose;
    }
}
