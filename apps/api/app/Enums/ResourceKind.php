<?php

namespace App\Enums;

enum ResourceKind: string
{
    case Place = 'place';
    case Asset = 'asset';
    case Crew = 'crew';

    public function label(): string
    {
        return match ($this) {
            self::Place => 'Places',
            self::Asset => 'Assets',
            self::Crew => 'Crew',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Place => 'Place',
            self::Asset => 'Asset',
            self::Crew => 'Crew',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Place => 'success',
            self::Asset => 'warning',
            self::Crew => 'info',
        };
    }

    public function allowsLinkedUser(): bool
    {
        return $this === self::Crew;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
