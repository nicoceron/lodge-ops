<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use App\Models\Property;
use App\Models\Resource;
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
            'type' => ResourceType::Room,
            'capacity' => 2,
            'attributes' => [],
            'is_active' => true,
        ];
    }
}
