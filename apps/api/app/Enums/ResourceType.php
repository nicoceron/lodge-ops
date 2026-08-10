<?php

namespace App\Enums;

enum ResourceType: string
{
    case Room = 'room';
    case Guide = 'guide';
    case Horse = 'horse';
    case Boat = 'boat';
    case Vehicle = 'vehicle';
    case Venue = 'venue';
    case Equipment = 'equipment';
    case Staff = 'staff';
}
