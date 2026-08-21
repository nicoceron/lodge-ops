<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Done = 'done';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
}
