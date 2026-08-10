<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuestRequest;
use App\Http\Requests\UpdateGuestRequest;
use App\Http\Resources\GuestResource;
use App\Http\Resources\ReservationResource;
use App\Models\Guest;
use App\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GuestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Guest::class);
        $search = trim((string) $request->query('search'));

        $guests = Guest::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return GuestResource::collection($guests);
    }

    public function store(StoreGuestRequest $request): GuestResource
    {
        $this->authorize('create', Guest::class);

        return new GuestResource(Guest::query()->create($request->validated()));
    }

    public function show(Guest $guest): GuestResource
    {
        $this->authorize('view', $guest);

        return new GuestResource($guest);
    }

    public function history(Request $request, Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        $historyQuery = Reservation::query()
            ->when(app(TenantContext::class)->membership()?->property_id, fn ($query, $propertyId) => $query->where('property_id', $propertyId))
            ->where(function ($query) use ($guest): void {
                $query->where('primary_guest_id', $guest->id)
                    ->orWhereHas('guests', fn ($guests) => $guests->whereKey($guest->id));
            });
        $reservations = (clone $historyQuery)
            ->with(['primaryGuest', 'program', 'guests', 'allocations.resource', 'allocations.serviceOccurrence'])
            ->orderByDesc('starts_at')
            ->get();
        $currency = app(TenantContext::class)->tenant()->currency;
        $countable = (clone $historyQuery)->whereNotIn('status', ['draft', 'cancelled']);

        return response()->json(['data' => [
            'guest' => (new GuestResource($guest))->resolve($request),
            'reservations' => ReservationResource::collection($reservations)->resolve($request),
            'stats' => [
                'stays' => (clone $countable)->count(),
                'lifetime_value_minor' => (int) (clone $countable)->where('currency', $currency)->sum('total_minor'),
                'currency' => $currency,
                'last_stay_at' => (clone $countable)->max('ends_at'),
            ],
        ]]);
    }

    public function update(UpdateGuestRequest $request, Guest $guest): GuestResource
    {
        $this->authorize('update', $guest);
        $guest->update($request->validated());

        return new GuestResource($guest->fresh());
    }

    public function destroy(Guest $guest): Response
    {
        $this->authorize('delete', $guest);
        $guest->delete();

        return response()->noContent();
    }
}
