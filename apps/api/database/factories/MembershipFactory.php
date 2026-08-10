<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Membership> */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'role' => MembershipRole::Operations, 'is_active' => true];
    }
}
