<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceRecord> */
class AttendanceRecordFactory extends Factory
{
    public function definition(): array
    {
        $workDate = fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d');

        return [
            'staff_id' => Staff::factory(),
            'store_id' => Store::factory(),
            'work_date' => $workDate,
            'clock_in_at' => "{$workDate} 19:00:00",
            'clock_out_at' => "{$workDate} 23:00:00",
            'working_minutes' => 240,
            'late_night_minutes' => 60,
        ];
    }
}
