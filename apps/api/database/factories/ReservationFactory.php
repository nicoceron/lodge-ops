<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Reservation> */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(1, 60))->startOfHour();

        return [
            'property_id' => Property::factory(),
            'confirmation_number' => 'RSV-'.Str::upper((string) Str::ulid()),
            'status' => ReservationStatus::Draft,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(2),
            'adults' => 2,
            'children' => 0,
            'currency' => 'COP',
            'subtotal_minor' => 40000000,
            'tax_minor' => 7600000,
            'total_minor' => 47600000,
        ];
    }
}
