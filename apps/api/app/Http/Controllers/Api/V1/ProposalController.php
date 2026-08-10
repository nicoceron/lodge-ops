<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProposalRequest;
use App\Http\Requests\UpdateProposalRequest;
use App\Http\Resources\ProposalResource;
use App\Http\Resources\ReservationResource;
use App\Models\Proposal;
use App\Services\ProposalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProposalController extends Controller
{
    public function __construct(private readonly ProposalService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Proposal::class);

        return ProposalResource::collection(Proposal::query()
            ->with(['property', 'primaryGuest', 'reservation'])
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('reference'), fn ($query, $reference) => $query->where('reference', $reference))
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function store(StoreProposalRequest $request): ProposalResource
    {
        $this->authorize('create', Proposal::class);

        return new ProposalResource($this->service->createDraft($request->validated(), $request->user()->id));
    }

    public function show(Proposal $proposal): ProposalResource
    {
        $this->authorize('view', $proposal);

        return new ProposalResource($proposal->load(['property', 'primaryGuest', 'reservation']));
    }

    public function update(UpdateProposalRequest $request, Proposal $proposal): ProposalResource
    {
        $this->authorize('update', $proposal);

        return new ProposalResource($this->service->updateDraft($proposal, $request->validated()));
    }

    public function send(Proposal $proposal): ProposalResource
    {
        $this->authorize('send', $proposal);

        return new ProposalResource($this->service->send($proposal));
    }

    public function revise(Request $request, Proposal $proposal): ProposalResource
    {
        $this->authorize('create', Proposal::class);
        $this->authorize('view', $proposal);

        return new ProposalResource($this->service->revise($proposal, $request->user()->id));
    }

    public function convert(Proposal $proposal): ReservationResource
    {
        $this->authorize('convert', $proposal);

        return new ReservationResource($this->service->convertToReservation($proposal));
    }
}
