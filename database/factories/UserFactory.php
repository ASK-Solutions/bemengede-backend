<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+2519'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
            'adult_confirmed_at' => now(),
        ];
    }
}
