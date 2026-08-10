<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FolioLineType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFolioLineRequest;
use App\Http\Resources\FolioLineResource;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Services\FolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolioController extends Controller
{
    public function __construct(private readonly FolioService $service) {}

    public function index(Reservation $reservation): JsonResponse
    {
        $this->authorize('viewAny', FolioLine::class);
        $this->authorize('view', $reservation);

        return response()->json([
            'data' => FolioLineResource::collection($reservation->folioLines()->with(['creator', 'reversal'])->orderBy('posted_at')->get()),
            'summary' => $this->service->summary($reservation),
        ]);
    }

    public function store(StoreFolioLineRequest $request, Reservation $reservation): FolioLineResource
    {
        $this->authorize('create', FolioLine::class);
        $this->authorize('view', $reservation);
        $data = $request->validated();

        return new FolioLineResource($this->service->append(
            reservation: $reservation,
            type: FolioLineType::from($data['type']),
            description: $data['description'],
            quantityThousandths: $data['quantity_thousandths'],
            unitAmountMinor: $data['unit_amount_minor'],
            actorId: $request->user()->id,
            metadata: $data['metadata'] ?? [],
        ));
    }

    public function reverse(Request $request, FolioLine $folioLine): FolioLineResource
    {
        $this->authorize('reverse', $folioLine);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        return new FolioLineResource($this->service->reverse($folioLine, $validated['reason'], $request->user()->id));
    }
}
