<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Administrator = 'administrator';
    case Owner = 'owner';
    case Manager = 'manager';
    case Sales = 'sales';
    case Operations = 'operations';
    case Guide = 'guide';
    case Kitchen = 'kitchen';
    case Housekeeping = 'housekeeping';
    case Finance = 'finance';
    case Viewer = 'viewer';

    public function canWrite(): bool
    {
        return ! in_array($this, [self::Owner, self::Viewer], true);
    }

    public function canViewFinance(): bool
    {
        return in_array($this, [self::Administrator, self::Owner, self::Manager, self::Finance], true);
    }

    public function canManageMoney(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Finance], true);
    }

    public function canManageGuestMoney(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Operations, self::Finance], true);
    }

    public function canViewGuestMoney(): bool
    {
        return in_array($this, [self::Administrator, self::Owner, self::Manager, self::Sales, self::Operations, self::Finance], true);
    }

    public function canManageReservations(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Sales, self::Operations], true);
    }

    public function canManageGuests(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Sales, self::Operations], true);
    }

    public function canManageOperations(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Operations, self::Guide, self::Kitchen, self::Housekeeping], true);
    }

    public function canManageConfiguration(): bool
    {
        return in_array($this, [self::Administrator, self::Manager], true);
    }

    public function canManageAvailability(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Operations, self::Guide], true);
    }

    public function canScheduleOperations(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Operations], true);
    }

    public function canManageTeam(): bool
    {
        return $this === self::Administrator;
    }

    public function canManageRetail(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Operations, self::Finance], true);
    }

    public function canViewRetail(): bool
    {
        return in_array($this, [self::Administrator, self::Owner, self::Manager, self::Operations, self::Finance], true);
    }

    public function canManageSales(): bool
    {
        return in_array($this, [self::Administrator, self::Manager, self::Sales], true);
    }
}
