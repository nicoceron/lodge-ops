<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CarbonImmutable|null $actual_start_at
 * @property CarbonImmutable|null $actual_end_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $closure_reason
 *
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'program_id' => $this->program_id,
            'primary_guest_id' => $this->primary_guest_id,
            'confirmation_number' => $this->confirmation_number,
            'status' => $this->status->value,
            'source' => $this->source,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'adults' => $this->adults,
            'children' => $this->children,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'tax_minor' => $this->tax_minor,
            'total_minor' => $this->total_minor,
            'folio_status' => $this->folio_status->value,
            'folio_closed_at' => $this->folio_closed_at,
            'folio_closed_by' => $this->folio_closed_by,
            'revision' => $this->revision,
            'notes' => $this->notes,
            'confirmed_at' => $this->confirmed_at,
            'actual_start_at' => $this->actual_start_at,
            'actual_end_at' => $this->actual_end_at,
            'cancelled_at' => $this->cancelled_at,
            'closure_reason' => $this->closure_reason,
            'hold_expires_at' => $this->hold_expires_at,
            'primary_guest' => $this->whenLoaded('primaryGuest', fn () => new GuestResource($this->primaryGuest)),
            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'display_color' => $this->program->display_color,
            ] : null),
            'guests' => GuestResource::collection($this->whenLoaded('guests')),
            'allocations' => AllocationResource::collection($this->whenLoaded('allocations')),
            'status_history' => ReservationStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'note_timeline' => $this->whenLoaded('noteTimeline', fn () => ReservationNoteResource::collection($this->noteTimeline)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
