<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CommunicationPurposePolicy extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requires_consent' => 'boolean',
            'is_active' => 'boolean',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
