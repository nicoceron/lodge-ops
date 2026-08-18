<?php

namespace App\Services\Automation;

use App\Enums\MembershipRole;
use App\Models\AutomationRule;
use App\Models\Membership;
use App\Models\Outbox;
use App\Models\Property;
use App\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

final class InternalStaffNotificationService
{
    public function __construct(
        private readonly AutomationTemplateRenderer $renderer,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     */
    public function deliver(
        Outbox $message,
        AutomationRule $rule,
        int $actionIndex,
        array $action,
        array $context,
    ): int {
        if (! $this->tenantContext->check()) {
            return 0;
        }

        $tenant = $this->tenantContext->tenant();
        if (! $tenant->is_active) {
            return 0;
        }

        $propertyId = $this->propertyId($context, $action);
        if ($propertyId === null) {
            return 0;
        }

        $property = Property::query()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->first();

        if ($property === null) {
            return 0;
        }

        $roles = $this->filterValues($action, 'roles')
            ?? $this->filterValues($action, 'role');
        if ($roles !== null) {
            $validRoles = array_map(
                static fn (MembershipRole $role): string => $role->value,
                MembershipRole::cases(),
            );

            if ($roles === [] || array_diff($roles, $validRoles) !== []) {
                return 0;
            }
        }

        $recipients = $this->filterValues($action, 'recipients')
            ?? $this->filterValues($action, 'recipient_ids');
        if ($recipients !== null && $recipients === []) {
            return 0;
        }

        $memberships = Membership::query()
            ->with('user')
            ->where('is_active', true)
            ->where(function (Builder $query) use ($propertyId): void {
                $query->whereNull('property_id')
                    ->orWhere('property_id', $propertyId);
            })
            ->when($roles !== null, fn (Builder $query): Builder => $query->whereIn('role', $roles))
            ->when(
                $recipients !== null,
                fn (Builder $query): Builder => $query->whereHas(
                    'user',
                    fn (Builder $userQuery): Builder => $userQuery
                        ->whereIn('id', $recipients)
                        ->orWhereIn('email', $recipients),
                ),
            )
            ->get();

        $title = $this->renderer->render($action['title'] ?? 'Inn operational alert', $context)
            ?: 'Inn operational alert';
        $body = $this->renderer->render($action['body'] ?? $action['description'] ?? null, $context);
        $status = $this->status($action['status'] ?? 'info');
        $delivered = 0;

        foreach ($memberships as $membership) {
            $user = $membership->user;
            $automationKey = implode(':', [
                $message->id,
                $rule->id,
                $actionIndex,
                'internal_notify',
                $user->id,
            ]);
            $notificationId = $this->notificationId($automationKey);

            if ($user->notifications()->whereKey($notificationId)->exists()) {
                continue;
            }

            $notification = Notification::make($notificationId)
                ->title($title)
                ->body($body)
                ->status($status)
                ->persistent()
                ->viewData([
                    'automation_key' => $automationKey,
                    'automation_rule_id' => $rule->id,
                    'outbox_id' => $message->id,
                    'action_index' => $actionIndex,
                    'action_type' => 'internal_notify',
                    'tenant_id' => $tenant->id,
                    'property_id' => $property->id,
                ])
                ->toDatabase();

            // The automation itself already runs in the outbox worker. Writing through
            // Laravel's database channel now makes the Filament inbox available as soon
            // as the automation transaction commits, without changing guest mail queueing.
            $notification->id = $notificationId;
            $user->notifyNow($notification, ['database']);
            $delivered++;
        }

        return $delivered;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $action
     */
    private function propertyId(array $context, array $action): ?string
    {
        $contextPropertyId = data_get($context, 'reservation.property_id')
            ?? data_get($context, 'payload.property_id')
            ?? data_get($context, 'property_id');
        $actionPropertyId = $action['property_id'] ?? null;

        if ($contextPropertyId !== null && $actionPropertyId !== null && $contextPropertyId !== $actionPropertyId) {
            return null;
        }

        if (is_string($contextPropertyId) && $contextPropertyId !== '') {
            return $contextPropertyId;
        }

        $reservationId = data_get($context, 'payment.reservation_id');
        if (is_string($reservationId) && $reservationId !== '') {
            $propertyId = Reservation::query()->whereKey($reservationId)->value('property_id');
            if (is_string($propertyId) && $propertyId !== '') {
                return $propertyId;
            }
        }

        return is_string($actionPropertyId) && $actionPropertyId !== ''
            ? $actionPropertyId
            : null;
    }

    /** @param array<string, mixed> $action @return list<string>|null */
    private function filterValues(array $action, string $key): ?array
    {
        if (! array_key_exists($key, $action)) {
            return null;
        }

        $value = $action[$key];
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): ?string => is_scalar($item) ? (string) $item : null,
                $value,
            ),
            static fn (?string $item): bool => $item !== null && $item !== '',
        ));
    }

    private function status(mixed $status): string
    {
        return in_array($status, ['danger', 'info', 'success', 'warning'], true)
            ? $status
            : 'info';
    }

    private function notificationId(string $automationKey): string
    {
        $hex = hash('sha256', $automationKey);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
