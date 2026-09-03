<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const CONSTRAINTS = [
        'attendance_quarter_hour_check',
        'attendance_time_range_check',
        'attendance_minutes_check',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE attendance_records ADD CONSTRAINT attendance_quarter_hour_check CHECK ('.
            'MINUTE(clock_in_at) IN (0, 15, 30, 45) AND SECOND(clock_in_at) = 0 AND '.
            'MINUTE(clock_out_at) IN (0, 15, 30, 45) AND SECOND(clock_out_at) = 0)',
        );
        DB::statement(
            'ALTER TABLE attendance_records ADD CONSTRAINT attendance_time_range_check CHECK ('.
            'clock_in_at >= TIMESTAMP(work_date, \'00:00:00\') AND '.
            'clock_out_at > clock_in_at AND '.
            'clock_out_at <= TIMESTAMP(DATE_ADD(work_date, INTERVAL 1 DAY), \'10:00:00\'))',
        );
        DB::statement(
            'ALTER TABLE attendance_records ADD CONSTRAINT attendance_minutes_check CHECK ('.
            'working_minutes = TIMESTAMPDIFF(MINUTE, clock_in_at, clock_out_at) AND '.
            'late_night_minutes <= working_minutes AND late_night_minutes <= 600)',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CONSTRAINTS as $constraint) {
            DB::statement("ALTER TABLE attendance_records DROP CHECK {$constraint}");
        }
    }
};
