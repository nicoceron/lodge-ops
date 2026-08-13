<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProgramController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Program::class);
        $membershipPropertyId = app(TenantContext::class)->propertyScopeId();

        return ProgramResource::collection(Program::query()
            ->with('requirements.category')
            ->when($membershipPropertyId, fn ($query) => $query->where('property_id', $membershipPropertyId))
            ->when($request->query('property_id'), fn ($query, $id) => $query->where('property_id', $id))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate(min(max(1, $request->integer('per_page', 50)), 100)));
    }

    public function show(Program $program): ProgramResource
    {
        $this->authorize('view', $program);

        return new ProgramResource($program->load('requirements.category'));
    }
}
