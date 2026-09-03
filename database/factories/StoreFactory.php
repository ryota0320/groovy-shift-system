<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Store> */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().'店',
            'opening_time' => '17:00',
            'closing_time' => '10:00',
            'is_active' => true,
        ];
    }
}
