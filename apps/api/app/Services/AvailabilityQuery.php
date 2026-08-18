<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ResourceCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class AvailabilityQuery
{
    /**
     * @return array{categories:list<array<string, mixed>>, resources:list<array<string, mixed>>}
     */
    public function forStay(
        string $propertyId,
        mixed $startsAt,
        mixed $endsAt,
        int $occupancy,
        ?string $categoryId = null,
    ): array {
        $starts = CarbonImmutable::parse($startsAt);
        $ends = CarbonImmutable::parse($endsAt);
        if ($starts >= $ends) {
            throw ValidationException::withMessages(['ends_at' => 'Departure must be after arrival.']);
        }
        if ($occupancy < 1) {
            throw ValidationException::withMessages(['adults' => 'At least one guest is required.']);
        }

        $categories = ResourceCategory::query()
            ->where('property_id', $propertyId)
            ->where('counts_as_stay', true)
            ->where('is_active', true)
            ->when($categoryId, fn ($query) => $query->whereKey($categoryId))
            ->with(['resources' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->orderBy('sort_order')
            ->get();

        $allResources = $categories->flatMap->resources;
        $unavailableResourceIds = $this->unavailableResourceIds($allResources, $starts, $ends);
        $buyoutActive = $this->buyoutConflict($propertyId, $starts, $ends);
        $resourceRows = [];
        $categoryRows = [];

        foreach ($categories as $category) {
            $available = $category->resources
                ->filter(fn (Resource $resource): bool => ! $buyoutActive
                    && ! $unavailableResourceIds->contains($resource->id)
                    && $resource->capacity >= $occupancy);
            foreach ($category->resources as $resource) {
                $resourceRows[] = [
                    'id' => $resource->id,
                    'category_id' => $category->id,
                    'category' => $category->name,
                    'name' => $resource->name,
                    'capacity' => $resource->capacity,
                    'is_buyout' => $resource->isBuyout(),
                    'available' => $available->contains('id', $resource->id),
                ];
            }
            $categoryRows[] = [
                'id' => $category->id,
                'name' => $category->name,
                'available_units' => $available->count(),
                'maximum_occupancy' => (int) $available->max('capacity'),
                'available' => $available->isNotEmpty(),
            ];
        }

        return ['categories' => $categoryRows, 'resources' => $resourceRows];
    }

    /** @param Collection<int, resource> $resources @return Collection<int, string> */
    private function unavailableResourceIds(Collection $resources, CarbonImmutable $starts, CarbonImmutable $ends): Collection
    {
        $ids = $resources->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        $blocked = ResourceBlock::query()
            ->whereIn('resource_id', $ids)
            ->where('starts_at', '<', $ends)
            ->where('ends_at', '>', $starts)
            ->pluck('resource_id');
        $allocated = Allocation::query()
            ->whereIn('resource_id', $ids)
            ->where('starts_at', '<', $ends)
            ->where('ends_at', '>', $starts)
            ->where(function ($query): void {
                $query->where('status', AllocationStatus::Confirmed)
                    ->orWhere(function ($query): void {
                        $query->where('status', AllocationStatus::Tentative)
                            ->whereHas('reservation', fn ($reservation) => $reservation
                                ->where('status', ReservationStatus::Hold)
                                ->where('hold_expires_at', '>', now()));
                    });
            })
            ->pluck('resource_id');

        return $blocked->merge($allocated)->filter()->unique()->values();
    }

    private function buyoutConflict(string $propertyId, CarbonImmutable $starts, CarbonImmutable $ends): bool
    {
        $buyoutIds = Resource::query()->where('property_id', $propertyId)->get()
            ->filter(fn (Resource $resource): bool => $resource->isBuyout())->pluck('id');

        return $buyoutIds->isNotEmpty() && (
            ResourceBlock::query()->whereIn('resource_id', $buyoutIds)
                ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->exists()
            || Allocation::query()->whereIn('resource_id', $buyoutIds)
                ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)
                ->where(function ($query): void {
                    $query->where('status', AllocationStatus::Confirmed)
                        ->orWhere(function ($query): void {
                            $query->where('status', AllocationStatus::Tentative)
                                ->whereHas('reservation', fn ($reservation) => $reservation
                                    ->where('status', ReservationStatus::Hold)
                                    ->where('hold_expires_at', '>', now()));
                        });
                })->exists()
        );
    }
}
