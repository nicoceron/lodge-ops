<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerHeartbeat extends Model
{
    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime', 'metadata' => 'array'];
    }
}
