<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property CarbonImmutable $last_seen_at
 * @property string $node
 */
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
