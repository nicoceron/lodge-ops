<?php

namespace Database\Factories;

use App\Enums\ResourceKind;
use App\Models\Property;
use App\Models\ResourceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ResourceCategory> */
class ResourceCategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'property_id' => Property::factory(),
            'kind' => ResourceKind::Place,
            'name' => str($name)->title()->toString(),
            'slug' => str($name)->slug()->toString(),
            'counts_as_stay' => true,
            'default_capacity' => 2,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function place(string $slug = 'room', string $name = 'Room', bool $countsAsStay = true): static
    {
        return $this->state(fn (): array => [
            'kind' => ResourceKind::Place,
            'slug' => $slug,
            'name' => $name,
            'counts_as_stay' => $countsAsStay,
        ]);
    }

    public function asset(string $slug, string $name): static
    {
        return $this->state(fn (): array => [
            'kind' => ResourceKind::Asset,
            'slug' => $slug,
            'name' => $name,
            'counts_as_stay' => false,
        ]);
    }

    public function crew(string $slug = 'guide', string $name = 'Guide'): static
    {
        return $this->state(fn (): array => [
            'kind' => ResourceKind::Crew,
            'slug' => $slug,
            'name' => $name,
            'counts_as_stay' => false,
        ]);
    }
}
