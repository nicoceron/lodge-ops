<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepositRequest;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use App\Models\Reservation;
use App\Services\DepositService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepositController extends Controller
{
    public function __construct(
        private readonly DepositService $service,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Deposit::class);

        $propertyId = $this->tenantContext->membership()?->property_id;

        return DepositResource::collection(Deposit::query()
            ->with(['reservation', 'payment'])
            ->when($propertyId, fn ($query) => $query->whereHas(
                'reservation',
                fn ($reservation) => $reservation->where('property_id', $propertyId),
            ))
            ->when($request->query('reservation_id'), fn ($query, $value) => $query->where('reservation_id', $value))
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->orderBy('due_at')
            ->paginate(min((int) $request->integer('per_page', 50), 100)));
    }

    public function store(StoreDepositRequest $request): DepositResource
    {
        $this->authorize('create', Deposit::class);
        $data = $request->validated();
        $propertyId = $this->tenantContext->membership()?->property_id;
        abort_unless(
            $propertyId === null || Reservation::query()
                ->whereKey($data['reservation_id'])
                ->where('property_id', $propertyId)
                ->exists(),
            403,
        );
        $reservation = Reservation::query()->findOrFail($data['reservation_id']);

        return new DepositResource($this->service->create($reservation, $data['amount_minor'], $data['due_at'] ?? null));
    }

    public function show(Deposit $deposit): DepositResource
    {
        $this->authorize('view', $deposit);

        return new DepositResource($deposit->load(['reservation', 'payment']));
    }

    public function waive(Request $request, Deposit $deposit): DepositResource
    {
        $this->authorize('waive', $deposit);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        return new DepositResource($this->service->waive($deposit, $validated['reason'], $request->user()->id));
    }
}
