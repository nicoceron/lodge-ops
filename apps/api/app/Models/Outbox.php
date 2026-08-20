<?php

namespace App\Models;

/** @property array<string, mixed> $payload @property string $event_type */
class Outbox extends TenantModel
{
    protected $table = 'outbox';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
