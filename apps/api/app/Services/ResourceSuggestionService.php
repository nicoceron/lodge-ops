<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Models\Allocation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ResourceSuggestionService
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $languages
     * @return Collection<int, array{id:string,name:string,capacity:int,reasons:list<string>,recent_assignments:int}>
     */
    public function suggest(
        ResourceType $type,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        int $quantity = 1,
        array $capabilities = [],
        array $languages = [],
        ?string $propertyId = null,
    ): Collection {
        return Resource::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->where('type', $type)
            ->where('is_active', true)
            ->where('capacity', '>=', $quantity)
            ->withCount(['allocations as recent_assignments' => fn ($query) => $query
                ->where('status', AllocationStatus::Confirmed)
                ->where('starts_at', '>=', now()->subDays(30))])
            ->get()
            ->filter(function (Resource $resource) use ($startsAt, $endsAt, $capabilities, $languages): bool {
                $skills = collect(data_get($resource->attributes, 'capabilities', []))->map(fn ($value) => mb_strtolower((string) $value));
                $spoken = collect(data_get($resource->attributes, 'languages', []))->map(fn ($value) => mb_strtolower((string) $value));
                $neededSkills = collect($capabilities)->map(fn ($value) => mb_strtolower($value));
                $neededLanguages = collect($languages)->map(fn ($value) => mb_strtolower($value));

                if ($neededSkills->diff($skills)->isNotEmpty() || $neededLanguages->diff($spoken)->isNotEmpty()) {
                    return false;
                }

                $propertyResourceIds = Resource::query()->where('property_id', $resource->property_id)->pluck('id');
                $buyoutResourceIds = Resource::query()
                    ->where('property_id', $resource->property_id)
                    ->get()
                    ->filter(fn (Resource $candidate): bool => $candidate->isBuyout())
                    ->pluck('id');
                $conflictingResourceIds = $resource->isBuyout()
                    ? $propertyResourceIds
                    : $buyoutResourceIds->push($resource->id)->unique();
                $blocked = ResourceBlock::query()
                    ->whereIn('resource_id', $conflictingResourceIds)
                    ->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt)
                    ->exists();
                $allocated = Allocation::query()
                    ->whereIn('resource_id', $conflictingResourceIds)
                    ->where(function ($query): void {
                        $query->where('status', AllocationStatus::Confirmed)
                            ->orWhere(function ($query): void {
                                $query->where('status', AllocationStatus::Tentative)
                                    ->whereHas('reservation', fn ($reservation) => $reservation
                                        ->where('status', ReservationStatus::Hold)
                                        ->where('hold_expires_at', '>', now()));
                            });
                    })
                    ->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt)
                    ->exists();

                return ! $blocked && ! $allocated;
            })
            ->sortBy(fn (Resource $resource): array => [$resource->recent_assignments, $resource->capacity, mb_strtolower($resource->name)])
            ->values()
            ->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'name' => $resource->name,
                'capacity' => $resource->capacity,
                'reasons' => array_values(array_filter([
                    $capabilities === [] ? null : 'Matches '.implode(', ', $capabilities),
                    $languages === [] ? null : 'Speaks '.implode(', ', $languages),
                    "{$resource->recent_assignments} assignments in the last 30 days",
                ])),
                'recent_assignments' => $resource->recent_assignments,
            ]);
    }
}
