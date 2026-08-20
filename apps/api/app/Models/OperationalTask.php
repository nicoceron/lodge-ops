<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $due_at
 * @property TaskStatus $status
 * @property string $title
 * @property string|null $reservation_id
 * @property string $property_id
 * @property int|null $assignee_id
 * @property string|null $description
 * @property string $priority
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $reopened_at
 * @property CarbonImmutable|null $escalated_at
 * @property string|null $escalation_reason
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $cancellation_reason
 * @property int $revision
 * @property int $generation
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
        return [
            'status' => TaskStatus::class,
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'generation' => 'integer',
            'revision' => 'integer',
            'metadata' => 'array',
        ];
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

    public function events(): HasMany
    {
        return $this->hasMany(OperationalTaskEvent::class)->orderBy('occurred_at');
    }

    public function checklistTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateVersion::class);
    }

    public function checklistTemplateItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateItem::class);
    }

    public function checklistException(): BelongsTo
    {
        return $this->belongsTo(ReservationChecklistException::class, 'reservation_checklist_exception_id');
    }
}
