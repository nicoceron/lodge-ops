<?php

namespace App\Filament\Resources;

use App\Models\TenantModel;
use App\Support\Tenancy\TenantContext;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

abstract class TenantResource extends Resource
{
    protected static bool $canCreateRecords = true;

    protected static bool $canEditRecords = true;

    protected static bool $canDeleteRecords = true;

    protected static ?string $viewCapability = null;

    protected static string $writeCapability = 'canWrite';

    protected static string $deleteCapability = 'canManageConfiguration';

    public static function canViewAny(): bool
    {
        return static::hasActiveMembership() && static::hasCapability(static::$viewCapability);
    }

    public static function canView(Model $record): bool
    {
        return static::belongsToCurrentTenant($record)
            && static::hasActiveMembership()
            && static::hasCapability(static::$viewCapability);
    }

    public static function canCreate(): bool
    {
        return static::$canCreateRecords && static::canWrite();
    }

    public static function canEdit(Model $record): bool
    {
        return static::$canEditRecords && static::belongsToCurrentTenant($record) && static::canWrite();
    }

    public static function canDelete(Model $record): bool
    {
        return static::$canDeleteRecords && static::belongsToCurrentTenant($record) && static::canManage();
    }

    public static function canDeleteAny(): bool
    {
        return static::$canDeleteRecords && static::canManage();
    }

    protected static function hasActiveMembership(): bool
    {
        $membership = app(TenantContext::class)->membership();

        return $membership?->is_active === true
            && $membership->tenant_id === app(TenantContext::class)->id()
            && $membership->user_id === auth()->id();
    }

    protected static function canWrite(): bool
    {
        return static::hasActiveMembership() && static::hasCapability(static::$writeCapability);
    }

    protected static function canManage(): bool
    {
        return static::hasActiveMembership() && static::hasCapability(static::$deleteCapability);
    }

    protected static function hasCapability(?string $capability): bool
    {
        if ($capability === null) {
            return true;
        }

        $role = app(TenantContext::class)->membership()?->role;

        return $role !== null && method_exists($role, $capability) && $role->{$capability}();
    }

    protected static function belongsToCurrentTenant(Model $record): bool
    {
        return $record instanceof TenantModel
            && $record->tenant_id === app(TenantContext::class)->id();
    }
}
