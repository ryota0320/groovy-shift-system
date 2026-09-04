<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'attendance_time_range_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf('ALTER TABLE attendance_records DROP CONSTRAINT %s', self::CONSTRAINT));
        DB::statement(sprintf(
            'ALTER TABLE attendance_records ADD CONSTRAINT %s CHECK ('.
            "clock_in_at >= TIMESTAMP(work_date, '12:00:00') AND ".
            "clock_in_at < TIMESTAMP(DATE_ADD(work_date, INTERVAL 1 DAY), '12:00:00') AND ".
            'clock_out_at > clock_in_at AND '.
            'clock_out_at < DATE_ADD(clock_in_at, INTERVAL 24 HOUR))',
            self::CONSTRAINT,
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $cannotRestorePreviousConstraint = DB::table('attendance_records')
            ->whereRaw("clock_in_at < TIMESTAMP(work_date, '17:00:00')")
            ->orWhereRaw("clock_out_at > TIMESTAMP(DATE_ADD(work_date, INTERVAL 1 DAY), '10:00:00')")
            ->exists();

        if ($cannotRestorePreviousConstraint) {
            throw new RuntimeException(
                '当日17:00より前または翌日10:00より後の勤怠が存在するため、以前の営業日制約へ戻せません。',
            );
        }

        DB::statement(sprintf('ALTER TABLE attendance_records DROP CONSTRAINT %s', self::CONSTRAINT));
        DB::statement(sprintf(
            'ALTER TABLE attendance_records ADD CONSTRAINT %s CHECK ('.
            "clock_in_at >= TIMESTAMP(work_date, '17:00:00') AND ".
            'clock_out_at > clock_in_at AND '.
            "clock_out_at <= TIMESTAMP(DATE_ADD(work_date, INTERVAL 1 DAY), '10:00:00'))",
            self::CONSTRAINT,
        ));
    }
};
