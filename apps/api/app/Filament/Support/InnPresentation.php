<?php

namespace App\Filament\Support;

use App\Models\Property;
use App\Models\Reservation;
use App\Models\StockLocation;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class InnPresentation
{
    /** @param array<BackedEnum> $cases */
    public static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(
            fn (BackedEnum $case): array => [$case->value => self::label($case->value)],
        )->all();
    }

    public static function label(BackedEnum|string|null $value): string
    {
        $raw = $value instanceof BackedEnum ? $value->value : $value;

        return Str::of((string) $raw)->replace('_', ' ')->title()->toString();
    }

    public static function statusColor(BackedEnum|string|null $status): string
    {
        $value = $status instanceof BackedEnum ? $status->value : $status;

        return match ($value) {
            'confirmed', 'checked_in', 'succeeded', 'done', 'active', 'configured', 'connected', 'won', 'paid', 'generated', 'published' => 'success',
            'hold', 'pending', 'in_progress', 'todo', 'proposal', 'accrued' => 'warning',
            'cancelled', 'failed', 'blocked', 'no_show', 'reversed', 'lost', 'disconnected' => 'danger',
            'checked_out', 'refunded', 'qualified' => 'info',
            default => 'gray',
        };
    }

    public static function priorityColor(?string $priority): string
    {
        return match ($priority) {
            'urgent' => 'danger',
            'high' => 'warning',
            'low' => 'gray',
            default => 'info',
        };
    }

    /** @return array<string, string> */
    public static function automationTriggerOptions(): array
    {
        return [
            'reservation.confirmed' => 'Reservation confirmed',
            'reservation.status_changed' => 'Reservation status changed',
            'reservation.arrival_approaching' => 'Arrival approaching',
            'reservation.checkout_completed' => 'Reservation checkout completed',
            'reservation.hold_expired' => 'Reservation hold expired',
            'deposit.created' => 'Deposit created',
            'deposit.overdue' => 'Deposit overdue',
            'deposit.waived' => 'Deposit waived',
            'payment.created' => 'Payment created',
            'payment.succeeded' => 'Payment succeeded',
            'payment.reversed' => 'Payment reversed',
            'proposal.sent' => 'Proposal sent',
            'proposal.accepted' => 'Proposal accepted',
        ];
    }

    /** @return array<string, string> */
    public static function propertyOptions(): array
    {
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        return Property::query()
            ->when($propertyId, fn (Builder $query, string $id): Builder => $query->whereKey($id))
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    public static function reservationOptions(?string $propertyId = null): array
    {
        $propertyId = app(TenantContext::class)->membership()->property_id ?? $propertyId;

        return Reservation::query()
            ->when($propertyId, fn (Builder $query): Builder => $query->where('property_id', $propertyId))
            ->orderByDesc('starts_at')
            ->limit(100)
            ->pluck('confirmation_number', 'id')
            ->all();
    }

    /** @return array<string, string> */
    public static function stockLocationOptions(): array
    {
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        return StockLocation::query()
            ->when($propertyId, fn (Builder $query): Builder => $query->where('property_id', $propertyId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function timezone(): string
    {
        return app(TenantContext::class)->check()
            ? app(TenantContext::class)->tenant()->timezone
            : 'UTC';
    }
}
