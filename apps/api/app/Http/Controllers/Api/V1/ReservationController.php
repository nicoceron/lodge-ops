<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\BookingQuote;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Services\CommitBookingQuote;
use App\Services\ReservationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReservationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewDirectory', Reservation::class);
        $membershipPropertyId = app(TenantContext::class)->membership()?->property_id;

        $reservations = Reservation::query()
            ->with(['primaryGuest', 'program', 'allocations.requestedCategory', 'allocations.resource', 'allocations.serviceOccurrence'])
            ->when($membershipPropertyId, fn ($query) => $query->where('property_id', $membershipPropertyId))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('property_id'), fn ($query, $propertyId) => $query->where('property_id', $propertyId))
            ->when($request->query('from'), fn ($query, $from) => $query->where('ends_at', '>', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->where('starts_at', '<', $to))
            ->orderBy('starts_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ReservationResource::collection($reservations);
    }

    public function store(StoreReservationRequest $request, CommitBookingQuote $commit): JsonResponse
    {
        $this->authorize('create', Reservation::class);
        $data = $request->validated();
        $quote = BookingQuote::query()->findOrFail($data['quote_id']);
        $this->assertMembershipProperty($quote->property_id);
        $reservation = $commit->handle(
            $quote,
            $data['primary_guest_id'] ?? null,
            [
                'first_name' => $data['guest_first_name'] ?? null,
                'last_name' => $data['guest_last_name'] ?? null,
                'email' => $data['guest_email'] ?? null,
                'phone' => $data['guest_phone'] ?? null,
                'language' => $data['guest_language'] ?? null,
                'dietary' => $data['guest_dietary'] ?? null,
            ],
            $data['companion_guest_ids'] ?? [],
            $data['source'] ?? null,
            $data['notes'] ?? null,
        );

        return (new ReservationResource($reservation->load(['primaryGuest', 'program', 'guests', 'allocations.requestedCategory', 'allocations.resource', 'allocations.serviceOccurrence'])))
            ->response()->setStatusCode(201);
    }

    public function show(Reservation $reservation): ReservationResource
    {
        $this->authorize('view', $reservation);

        return new ReservationResource($reservation->load(['primaryGuest', 'program', 'guests', 'allocations.requestedCategory', 'allocations.resource', 'allocations.serviceOccurrence', 'statusHistory.actor', 'noteTimeline.creator', 'changes.actor']));
    }

    public function update(
        UpdateReservationRequest $request,
        Reservation $reservation,
    ): ReservationResource {
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
            if (! in_array($locked->status, [ReservationStatus::Draft, ReservationStatus::Hold], true)) {
                throw ValidationException::withMessages(['status' => 'Only draft or held reservations may be edited.']);
            }
            if ($expectedRevision !== null && (int) $expectedRevision !== $locked->revision) {
                throw new ConflictHttpException('The reservation changed after it was loaded. Refresh and try again.');
            }

            $data = $request->validated();
            $guestIds = Arr::pull($data, 'guest_ids', null);
            $originalPrimaryGuestId = $locked->primary_guest_id;
            $data['revision'] = $locked->revision + 1;
            $locked->forceFill($data)->save();

            $primaryGuestChanged = array_key_exists('primary_guest_id', $data)
                && $locked->primary_guest_id !== $originalPrimaryGuestId;
            if ($guestIds !== null || $primaryGuestChanged) {
                if ($guestIds === null) {
                    $guestIds = ReservationGuest::query()
                        ->where('reservation_id', $locked->id)
                        ->where('role', '!=', 'primary')
                        ->pluck('guest_id')
                        ->all();
                }
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

        return new ReservationResource($reservation->fresh()->load(['primaryGuest', 'program', 'guests', 'allocations.requestedCategory', 'allocations.resource', 'allocations.serviceOccurrence']));
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
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn (): bool => in_array(
                $request->string('status')->toString(),
                [ReservationStatus::Cancelled->value, ReservationStatus::NoShow->value],
                true,
            ))],
        ]);

        return new ReservationResource($service->transition(
            $reservation,
            ReservationStatus::from($validated['status']),
            $validated['hold_minutes'] ?? null,
            ['reason' => $validated['reason'] ?? null],
        ));
    }

    private function assertMembershipProperty(string $propertyId): void
    {
        $membershipPropertyId = app(TenantContext::class)->membership()?->property_id;
        if ($membershipPropertyId !== null && $membershipPropertyId !== $propertyId) {
            throw ValidationException::withMessages(['property_id' => 'The property is outside your active membership scope.']);
        }
    }
}
