<?php

namespace App\Services;

use App\Enums\ResourceKind;
use App\Models\Property;
use App\Models\ResourceCategory;
use Illuminate\Support\Collection;

class ResourceCatalog
{
    /**
     * @param  iterable<array{kind: ResourceKind, slug: string, name: string, counts_as_stay?: bool, default_capacity?: int, sort_order?: int, is_active?: bool}>  $definitions
     * @return Collection<string, ResourceCategory>
     */
    public function ensure(Property $property, iterable $definitions): Collection
    {
        $categories = collect();
        foreach ($definitions as $definition) {
            $categories->put($definition['slug'], ResourceCategory::query()->updateOrCreate(
                [
                    'property_id' => $property->id,
                    'slug' => $definition['slug'],
                ],
                [
                    'kind' => $definition['kind'],
                    'name' => $definition['name'],
                    'counts_as_stay' => $definition['counts_as_stay'] ?? false,
                    'default_capacity' => $definition['default_capacity'] ?? 1,
                    'sort_order' => $definition['sort_order'] ?? 0,
                    'is_active' => $definition['is_active'] ?? true,
                ],
            ));
        }

        return $categories;
    }

    public function category(Property|string $property, string $slug): ResourceCategory
    {
        $propertyId = $property instanceof Property ? $property->id : $property;

        return ResourceCategory::query()
            ->where('property_id', $propertyId)
            ->where('slug', $slug)
            ->firstOr(fn () => throw new \InvalidArgumentException("Unknown resource category [{$slug}] for property [{$propertyId}]."));
    }
}
