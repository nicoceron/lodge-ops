<?php

namespace App\Models;

class IntegrationConnection extends TenantModel
{
    public const TYPES = [
        'email' => 'Email',
        'calendar' => 'Calendar',
        'accounting' => 'Accounting',
        'payment' => 'Payment',
        'signature' => 'Signature',
        'webhook' => 'Webhook',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
