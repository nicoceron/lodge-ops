<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Allocation;
use App\Models\Program;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Services\AllocationWorkflowService;
use App\Services\AvailabilityService;
use App\Services\ReservationService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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

    public function store(StoreReservationRequest $request, AllocationWorkflowService $allocationsService): ReservationResource
    {
        $this->authorize('create', Reservation::class);
        $data = $request->validated();
        $this->assertMembershipProperty($data['property_id']);

        $reservation = DB::transaction(function () use ($data, $allocationsService): Reservation {
            $allocations = Arr::pull($data, 'allocations', []);
            $guestIds = Arr::pull($data, 'guest_ids', []);
            $this->assertProgramProperty($data['program_id'] ?? null, $data['property_id']);
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
                $allocationsService->create($reservation, [
                    ...$allocation,
                    'starts_at' => $allocation['starts_at'] ?? $reservation->starts_at,
                    'ends_at' => $allocation['ends_at'] ?? $reservation->ends_at,
                ]);
            }

            return $reservation;
        });

        return new ReservationResource($reservation->load(['primaryGuest', 'program', 'guests', 'allocations.requestedCategory', 'allocations.resource', 'allocations.serviceOccurrence']));
    }

    public function show(Reservation $reservation): ReservationResource
    {
        $this->authorize('view', $reservation);

        return new ReservationResource($reservation->load(['primaryGuest', 'program', 'guests', 'allocations.requestedCategory', 'allocations.resource', 'allocations.serviceOccurrence', 'statusHistory.actor', 'noteTimeline.creator']));
    }

    public function update(
        UpdateReservationRequest $request,
        Reservation $reservation,
        AvailabilityService $availability,
    ): ReservationResource {
        $this->authorize('update', $reservation);

        if (! in_array($reservation->status, [ReservationStatus::Draft, ReservationStatus::Hold], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or held reservations may be edited.']);
        }

        $expectedRevision = $request->header('If-Match');
        if ($expectedRevision !== null && ! ctype_digit($expectedRevision)) {
            throw ValidationException::withMessages(['If-Match' => 'The If-Match header must contain the observed numeric revision.']);
        }

        $reservation = DB::transaction(function () use ($request, $reservation, $expectedRevision, $availability): Reservation {
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
            $originalStartsAt = $locked->starts_at;
            $originalEndsAt = $locked->ends_at;
            $propertyId = $data['property_id'] ?? $locked->property_id;
            $startsAt = array_key_exists('starts_at', $data)
                ? CarbonImmutable::parse($data['starts_at'])
                : $originalStartsAt;
            $endsAt = array_key_exists('ends_at', $data)
                ? CarbonImmutable::parse($data['ends_at'])
                : $originalEndsAt;
            $allocations = Allocation::query()
                ->where('reservation_id', $locked->id)
                ->lockForUpdate()
                ->get();

            if ($startsAt->greaterThanOrEqualTo($endsAt)) {
                throw ValidationException::withMessages([
                    'ends_at' => 'The reservation end must be after its start.',
                ]);
            }
            $this->assertMembershipProperty($propertyId);
            if ($propertyId !== $locked->property_id && $allocations->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'property_id' => 'A reservation with allocation history cannot change property. Create a new reservation for the other property.',
                ]);
            }
            $this->assertProgramProperty(
                $data['program_id'] ?? $locked->program_id,
                $propertyId,
            );

            $activeAllocations = $allocations->filter(
                fn ($allocation): bool => $allocation->status !== AllocationStatus::Released,
            );
            $datesChanged = ! $startsAt->equalTo($originalStartsAt) || ! $endsAt->equalTo($originalEndsAt);
            if ($datesChanged) {
                foreach ($activeAllocations as $allocation) {
                    $followsStayDates = $allocation->getAttribute('service_occurrence_id') === null
                        && $allocation->starts_at->equalTo($originalStartsAt)
                        && $allocation->ends_at->equalTo($originalEndsAt);
                    if (! $followsStayDates && ($allocation->starts_at->lessThan($startsAt) || $allocation->ends_at->greaterThan($endsAt))) {
                        throw ValidationException::withMessages([
                            'starts_at' => 'The edited stay must continue to contain every dated allocation.',
                        ]);
                    }
                }
            }
            if (array_key_exists('subtotal_minor', $data) || array_key_exists('tax_minor', $data)) {
                $data['total_minor'] = ($data['subtotal_minor'] ?? $locked->subtotal_minor) + ($data['tax_minor'] ?? $locked->tax_minor);
            }
            $data['revision'] = $locked->revision + 1;
            $locked->forceFill($data)->save();

            if ($datesChanged) {
                foreach ($activeAllocations as $allocation) {
                    if ($allocation->getAttribute('service_occurrence_id') !== null
                        || ! $allocation->starts_at->equalTo($originalStartsAt)
                        || ! $allocation->ends_at->equalTo($originalEndsAt)) {
                        continue;
                    }

                    $allocation->forceFill(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
                    if ($locked->status === ReservationStatus::Hold) {
                        $availability->assertAvailable($allocation);
                    }
                    $allocation->save();
                }
            }

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

    private function assertProgramProperty(?string $programId, string $propertyId): void
    {
        if ($programId !== null && ! Program::query()->whereKey($programId)->where('property_id', $propertyId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['program_id' => 'The program must be active and belong to the reservation property.']);
        }
    }

    private function assertMembershipProperty(string $propertyId): void
    {
        $membershipPropertyId = app(TenantContext::class)->membership()?->property_id;
        if ($membershipPropertyId !== null && $membershipPropertyId !== $propertyId) {
            throw ValidationException::withMessages(['property_id' => 'The property is outside your active membership scope.']);
        }
    }
}
