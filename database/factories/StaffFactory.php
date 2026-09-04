<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Staff> */
class StaffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'display_name' => null,
            'employment_type' => EmploymentType::PartTime,
            'hired_at' => fake()->optional()->date(),
            'retired_at' => null,
        ];
    }

    public function employee(): static
    {
        return $this->state(fn (): array => [
            'employment_type' => EmploymentType::Employee,
        ]);
    }

    public function partTime(): static
    {
        return $this->state(fn (): array => [
            'employment_type' => EmploymentType::PartTime,
        ]);
    }
}
