<?php

namespace App\Services;

use App\Enums\HousekeepingStatus;
use App\Enums\ResourceKind;
use App\Models\Resource;
use Illuminate\Validation\ValidationException;

class HousekeepingService
{
    public function update(Resource $resource, HousekeepingStatus $status, ?int $actorId): Resource
    {
        $resource->loadMissing('category');
        if ($resource->category->kind !== ResourceKind::Place) {
            throw ValidationException::withMessages([
                'resource_id' => 'Housekeeping state can only be recorded for places.',
            ]);
        }

        $resource->update([
            'housekeeping_status' => $status,
            'housekeeping_updated_at' => now(),
            'housekeeping_updated_by' => $actorId,
        ]);

        return $resource->fresh(['category', 'housekeepingUpdatedBy']);
    }
}
