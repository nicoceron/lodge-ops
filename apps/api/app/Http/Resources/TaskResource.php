<?php

namespace App\Http\Resources;

use App\Models\OperationalTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OperationalTask */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'reservation_id' => $this->reservation_id,
            'assignee_id' => $this->assignee_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority,
            'due_at' => $this->due_at,
            'completed_at' => $this->completed_at,
            'started_at' => $this->started_at,
            'failed_at' => $this->failed_at,
            'failure_reason' => $this->failure_reason,
            'reopened_at' => $this->reopened_at,
            'escalated_at' => $this->escalated_at,
            'escalation_reason' => $this->escalation_reason,
            'superseded_at' => $this->superseded_at,
            'cancellation_reason' => $this->cancellation_reason,
            'revision' => $this->revision,
            'generation' => $this->generation,
            'overdue' => $this->due_at?->isPast() === true && ! in_array($this->status->value, ['done', 'cancelled', 'superseded'], true),
            'metadata' => $this->metadata,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'events' => $this->whenLoaded('events'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
