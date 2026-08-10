<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReservationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Reservation::class);

        $reservations = Reservation::query()
            ->with(['primaryGuest', 'allocations.resource'])
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('property_id'), fn ($query, $propertyId) => $query->where('property_id', $propertyId))
            ->when($request->query('from'), fn ($query, $from) => $query->where('ends_at', '>', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->where('starts_at', '<', $to))
            ->orderBy('starts_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ReservationResource::collection($reservations);
    }

    public function store(StoreReservationRequest $request): ReservationResource
    {
        $this->authorize('create', Reservation::class);
        $data = $request->validated();

        $reservation = DB::transaction(function () use ($data): Reservation {
            $allocations = Arr::pull($data, 'allocations', []);
            $guestIds = Arr::pull($data, 'guest_ids', []);
            $data['confirmation_number'] = 'RSV-'.Str::upper((string) Str::ulid());
            $data['status'] = ReservationStatus::Draft;
            $data['total_minor'] = ($data['subtotal_minor'] ?? 0) + ($data['tax_minor'] ?? 0);

            $reservation = Reservation::query()->create($data);

            $guestIds = array_values(array_unique(array_filter([...$guestIds, $reservation->primary_guest_id])));
            foreach ($guestIds as $guestId) {
                ReservationGuest::query()->create([
                    'reservation_id' => $reservation->id,
                    'guest_id' => $guestId,
                    'role' => $guestId === $reservation->primary_guest_id ? 'primary' : 'guest',
                ]);
            }

            foreach ($allocations as $allocation) {
                $reservation->allocations()->create([
                    ...$allocation,
                    'status' => AllocationStatus::Tentative,
                    'starts_at' => $allocation['starts_at'] ?? $reservation->starts_at,
                    'ends_at' => $allocation['ends_at'] ?? $reservation->ends_at,
                ]);
            }

            return $reservation;
        });

        return new ReservationResource($reservation->load(['primaryGuest', 'guests', 'allocations.resource']));
    }

    public function show(Reservation $reservation): ReservationResource
    {
        $this->authorize('view', $reservation);

        return new ReservationResource($reservation->load(['primaryGuest', 'guests', 'allocations.resource']));
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation): ReservationResource
    {
        $this->authorize('update', $reservation);

        if (! in_array($reservation->status, [ReservationStatus::Draft, ReservationStatus::Hold], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or held reservations may be edited.']);
        }

        $expectedRevision = $request->header('If-Match');
        if ($expectedRevision !== null && ! ctype_digit($expectedRevision)) {
            throw ValidationException::withMessages(['If-Match' => 'The If-Match header must contain the observed numeric revision.']);
        }

        $reservation = DB::transaction(function () use ($request, $reservation, $expectedRevision): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($expectedRevision !== null && (int) $expectedRevision !== $locked->revision) {
                throw new ConflictHttpException('The reservation changed after it was loaded. Refresh and try again.');
            }

            $data = $request->validated();
            $guestIds = Arr::pull($data, 'guest_ids', null);
            if (array_key_exists('subtotal_minor', $data) || array_key_exists('tax_minor', $data)) {
                $data['total_minor'] = ($data['subtotal_minor'] ?? $locked->subtotal_minor) + ($data['tax_minor'] ?? $locked->tax_minor);
            }
            $data['revision'] = $locked->revision + 1;
            $locked->update($data);

            if ($guestIds !== null) {
                $locked->guests()->detach();
                foreach (array_values(array_unique(array_filter([...$guestIds, $locked->primary_guest_id]))) as $guestId) {
                    ReservationGuest::query()->create([
                        'reservation_id' => $locked->id,
                        'guest_id' => $guestId,
                        'role' => $guestId === $locked->primary_guest_id ? 'primary' : 'guest',
                    ]);
                }
            }

            return $locked;
        });

        return new ReservationResource($reservation->fresh()->load(['primaryGuest', 'guests', 'allocations.resource']));
    }

    public function confirm(Reservation $reservation, ReservationService $service): ReservationResource
    {
        $this->authorize('transition', $reservation);

        return new ReservationResource($service->confirm($reservation));
    }

    public function transition(Request $request, Reservation $reservation, ReservationService $service): ReservationResource
    {
        $this->authorize('transition', $reservation);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ReservationStatus::class)],
            'hold_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ]);

        return new ReservationResource($service->transition(
            $reservation,
            ReservationStatus::from($validated['status']),
            $validated['hold_minutes'] ?? null,
        ));
    }
}
