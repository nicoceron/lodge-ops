<?php

namespace App\Enums;

enum ProviderEventState: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Failed = 'failed';
    case Mismatched = 'mismatched';
}
