<?php

namespace App\Enums;

enum HousekeepingStatus: string
{
    case Clean = 'clean';
    case Dirty = 'dirty';
    case InProgress = 'in_progress';
    case Inspected = 'inspected';
    case OutOfService = 'out_of_service';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }

    public function color(): string
    {
        return match ($this) {
            self::Clean, self::Inspected => 'success',
            self::Dirty => 'danger',
            self::InProgress => 'warning',
            self::OutOfService => 'gray',
        };
    }
}
