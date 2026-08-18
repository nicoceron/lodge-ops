<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationChangeResource;
use App\Http\Resources\ReservationResource;
use App\Models\Allocation;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\Resource;
use App\Services\AmendReservation;
use App\Services\CancelReservation;
use App\Services\CompleteRefund;
use App\Services\MarkNoShow;
use App\Services\ReallocateResource;
use App\Services\RequestRefund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReservationChangeController extends Controller
{
    public function index(Reservation $reservation): AnonymousResourceCollection
    {
        $this->authorize('view', $reservation);

        return ReservationChangeResource::collection($reservation->changes()->with('actor')->paginate(50));
    }

    public function amend(Request $request, Reservation $reservation, AmendReservation $command): ReservationResource
    {
        $this->authorize('update', $reservation);
        $data = $request->validate([
            'rate_plan_id' => ['required', 'uuid'],
            'resource_category_id' => ['required', 'uuid'],
            'resource_id' => ['nullable', 'uuid'],
            'program_id' => ['nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'adults' => ['required', 'integer', 'min:1', 'max:1000'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        return new ReservationResource($command->handle($reservation, $data, $request->user()?->id));
    }

    public function reallocate(Request $request, Reservation $reservation, ReallocateResource $command): ReservationResource
    {
        $this->authorize('reallocate', $reservation);
        $data = $request->validate([
            'allocation_id' => ['required', 'uuid'],
            'resource_id' => ['required', 'uuid'],
            'swap_allocation_id' => ['nullable', 'uuid', 'different:allocation_id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return new ReservationResource($command->handle(
            $reservation,
            Allocation::query()->findOrFail($data['allocation_id']),
            Resource::query()->findOrFail($data['resource_id']),
            $request->user()?->id,
            isset($data['swap_allocation_id']) ? Allocation::query()->findOrFail($data['swap_allocation_id']) : null,
            $data['reason'] ?? null,
        ));
    }

    public function cancel(Request $request, Reservation $reservation, CancelReservation $command): ReservationResource
    {
        $this->authorize('transition', $reservation);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new ReservationResource($command->handle($reservation, $data['reason'], $request->user()?->id));
    }

    public function noShow(Request $request, Reservation $reservation, MarkNoShow $command): ReservationResource
    {
        $this->authorize('transition', $reservation);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new ReservationResource($command->handle($reservation, $data['reason'], $request->user()?->id));
    }

    public function requestRefund(Request $request, Reservation $reservation, RequestRefund $command): ReservationChangeResource
    {
        $this->authorize('requestRefund', $reservation);
        $data = $request->validate([
            'payment_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $change = $command->handle(
            $reservation,
            Payment::query()->findOrFail($data['payment_id']),
            $data['amount_minor'],
            $data['reason'],
            $request->user()?->id,
        );

        return new ReservationChangeResource($change);
    }

    public function completeRefund(Request $request, Reservation $reservation, ReservationChange $refund, CompleteRefund $command): ReservationChangeResource
    {
        $this->authorize('completeRefund', $reservation);
        abort_unless($refund->reservation_id === $reservation->id, 404);
        $data = $request->validate(['reference' => ['required', 'string', 'max:255']]);

        return new ReservationChangeResource($command->handle($refund, $data['reference'], $request->user()?->id));
    }

    public function failRefund(Request $request, Reservation $reservation, ReservationChange $refund, CompleteRefund $command): ReservationChangeResource
    {
        $this->authorize('completeRefund', $reservation);
        abort_unless($refund->reservation_id === $reservation->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new ReservationChangeResource($command->fail($refund, $data['reason'], $request->user()?->id));
    }
}
