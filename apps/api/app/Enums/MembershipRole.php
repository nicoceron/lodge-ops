<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';
    case Viewer = 'viewer';

    public function canWrite(): bool
    {
        return $this !== self::Viewer;
    }

    public function canManageMoney(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }
}
