<?php

namespace App\Enums;

enum ProviderRefundState: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Mismatched = 'mismatched';
}
