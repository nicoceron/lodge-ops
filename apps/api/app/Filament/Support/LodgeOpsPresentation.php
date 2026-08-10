<?php

namespace App\Filament\Support;

use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Illuminate\Support\Str;

final class LodgeOpsPresentation
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

    public static function timezone(): string
    {
        return app(TenantContext::class)->check()
            ? app(TenantContext::class)->tenant()->timezone
            : 'UTC';
    }
}
