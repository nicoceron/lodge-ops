<?php

namespace App\Enums;

enum MembershipRole: string
{
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
        return $this !== self::Viewer;
    }

    public function canManageMoney(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Finance], true);
    }

    public function canManageReservations(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Sales, self::Operations], true);
    }

    public function canManageGuests(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Sales, self::Operations], true);
    }

    public function canManageOperations(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Operations, self::Guide, self::Kitchen, self::Housekeeping], true);
    }

    public function canManageConfiguration(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }
}
