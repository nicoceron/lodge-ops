<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $due_at
 * @property TaskStatus $status
 * @property string $title
 * @property string|null $reservation_id
 * @property CarbonImmutable|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property-read Property $property
 * @property-read Reservation|null $reservation
 * @property-read User|null $assignee
 * @property-read ProgramTaskTemplate|null $programTaskTemplate
 */
class OperationalTask extends TenantModel
{
    protected $table = 'operational_tasks';

    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function programTaskTemplate(): BelongsTo
    {
        return $this->belongsTo(ProgramTaskTemplate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
