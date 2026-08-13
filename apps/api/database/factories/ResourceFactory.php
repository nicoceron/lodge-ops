<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Services\ResourceCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<resource> */
class ResourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Room '.fake()->unique()->numberBetween(1, 999),
            'code' => fake()->unique()->bothify('R-###'),
            'capacity' => 2,
            'attributes' => [],
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Resource $resource): void {
            if (blank($resource->property_id)) {
                return;
            }

            $slug = 'room';
            if (filled($resource->category_id)) {
                $existing = ResourceCategory::query()->find($resource->category_id);
                if ($existing?->property_id === $resource->property_id) {
                    return;
                }
                $slug = $existing?->slug ?? 'room';
            }

            $resource->category_id = app(ResourceCatalog::class)
                ->category($resource->property_id, $slug)
                ->id;
        });
    }

    public function catalog(string $slug): static
    {
        return $this->state(function (array $attributes) use ($slug): array {
            $propertyId = $attributes['property_id'] ?? null;
            if (! is_string($propertyId)) {
                return [];
            }

            return [
                'category_id' => app(ResourceCatalog::class)->category($propertyId, $slug)->id,
            ];
        })->afterMaking(function (Resource $resource) use ($slug): void {
            if (blank($resource->property_id)) {
                return;
            }
            $resource->category_id = app(ResourceCatalog::class)->category($resource->property_id, $slug)->id;
        });
    }

    public function room(): static
    {
        return $this->catalog('room')->state(fn (): array => [
            'name' => 'Room '.fake()->unique()->numberBetween(1, 999),
            'code' => fake()->unique()->bothify('R-###'),
        ]);
    }

    public function guide(): static
    {
        return $this->catalog('guide')->state(fn (): array => [
            'name' => fake()->name(),
            'code' => fake()->unique()->bothify('G-###'),
            'capacity' => 1,
        ]);
    }

    public function venue(): static
    {
        return $this->catalog('venue')->state(fn (): array => [
            'name' => 'Venue '.fake()->unique()->word(),
            'code' => fake()->unique()->bothify('V-###'),
            'capacity' => 1,
        ]);
    }
}
