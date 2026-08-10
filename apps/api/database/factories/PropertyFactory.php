<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Property> */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Lodge',
            'code' => fake()->unique()->bothify('L##??'),
            'timezone' => 'America/Bogota',
            'is_active' => true,
        ];
    }
}
