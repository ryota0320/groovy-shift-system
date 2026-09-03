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

        $invalidRecordsExist = DB::table('attendance_records')
            ->whereRaw("clock_in_at < TIMESTAMP(work_date, '17:00:00')")
            ->exists();

        if ($invalidRecordsExist) {
            throw new RuntimeException(
                '営業日当日17:00より前の勤怠が存在するため、営業日範囲制約を更新できません。work_dateを確認してください。',
            );
        }

        DB::statement(sprintf('ALTER TABLE attendance_records DROP CHECK %s', self::CONSTRAINT));
        DB::statement(sprintf(
            'ALTER TABLE attendance_records ADD CONSTRAINT %s CHECK ('.
            "clock_in_at >= TIMESTAMP(work_date, '17:00:00') AND ".
            'clock_out_at > clock_in_at AND '.
            "clock_out_at <= TIMESTAMP(DATE_ADD(work_date, INTERVAL 1 DAY), '10:00:00'))",
            self::CONSTRAINT,
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf('ALTER TABLE attendance_records DROP CHECK %s', self::CONSTRAINT));
        DB::statement(sprintf(
            'ALTER TABLE attendance_records ADD CONSTRAINT %s CHECK ('.
            "clock_in_at >= TIMESTAMP(work_date, '00:00:00') AND ".
            'clock_out_at > clock_in_at AND '.
            "clock_out_at <= TIMESTAMP(DATE_ADD(work_date, INTERVAL 1 DAY), '10:00:00'))",
            self::CONSTRAINT,
        ));
    }
};
