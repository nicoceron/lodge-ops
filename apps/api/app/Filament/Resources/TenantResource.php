<?php

namespace App\Filament\Resources;

use App\Models\TenantModel;
use App\Support\Tenancy\TenantContext;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

abstract class TenantResource extends Resource
{
    protected static bool $canCreateRecords = true;

    protected static bool $canEditRecords = true;

    protected static bool $canDeleteRecords = true;

    protected static ?string $viewCapability = null;

    protected static string $writeCapability = 'canWrite';

    protected static string $deleteCapability = 'canManageConfiguration';

    protected static ?string $propertyRelationship = null;

    protected static bool $includeTenantWideForProperty = false;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        if ($propertyId === null) {
            return $query;
        }

        if (Schema::hasColumn($query->getModel()->getTable(), 'property_id')) {
            if (static::$includeTenantWideForProperty) {
                return $query->where(function (Builder $scope) use ($propertyId): void {
                    $scope->where($scope->getModel()->qualifyColumn('property_id'), $propertyId)
                        ->orWhereNull($scope->getModel()->qualifyColumn('property_id'));
                });
            }

            return $query->where($query->getModel()->qualifyColumn('property_id'), $propertyId);
        }

        if (static::$propertyRelationship !== null) {
            return $query->whereHas(
                static::$propertyRelationship,
                fn (Builder $relationship) => $relationship->where('property_id', $propertyId),
            );
        }

        return $query;
    }

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
        if (! $record instanceof TenantModel || $record->tenant_id !== app(TenantContext::class)->id()) {
            return false;
        }

        $propertyId = app(TenantContext::class)->membership()?->property_id;

        if ($propertyId === null) {
            return true;
        }

        if (array_key_exists('property_id', $record->getAttributes())) {
            if (static::$includeTenantWideForProperty && $record->getAttribute('property_id') === null) {
                return true;
            }

            return $record->getAttribute('property_id') === $propertyId;
        }

        if (static::$propertyRelationship !== null) {
            return data_get($record, static::$propertyRelationship.'.property_id') === $propertyId;
        }

        return true;
    }
}
