<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalTask extends TenantModel
{
    protected $table = 'operational_tasks';

    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function programTaskTemplate(): BelongsTo
    {
        return $this->belongsTo(ProgramTaskTemplate::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
