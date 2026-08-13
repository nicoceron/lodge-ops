<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationNoteResource;
use App\Models\Reservation;
use App\Models\ReservationNote;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ReservationNoteController extends Controller
{
    public function index(Reservation $reservation): AnonymousResourceCollection
    {
        $this->authorize('view', $reservation);
        $this->authorize('viewAny', ReservationNote::class);

        return ReservationNoteResource::collection($reservation->noteTimeline()->with('creator:id,name')->paginate(50));
    }

    public function store(Request $request, Reservation $reservation): ReservationNoteResource
    {
        $this->authorize('view', $reservation);
        $this->authorize('create', ReservationNote::class);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ReservationNote::KINDS))],
            'body' => ['required', 'string', 'max:10000'],
            'occurred_at' => ['sometimes', 'date'],
        ]);

        $note = new ReservationNote;
        $note->reservation()->associate($reservation);
        $note->kind = $data['kind'];
        $note->body = $data['body'];
        $note->occurred_at = CarbonImmutable::parse($data['occurred_at'] ?? now());
        $note->created_by = $request->user()->id;
        $note->save();

        return new ReservationNoteResource($note->load('creator:id,name'));
    }
}
