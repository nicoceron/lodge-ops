<?php

namespace App\Enums;

enum FolioStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
