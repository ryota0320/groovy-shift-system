<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'shifts_type_shape_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf('ALTER TABLE shifts DROP CHECK %s', self::CONSTRAINT));
        DB::statement(sprintf(
            'ALTER TABLE shifts ADD CONSTRAINT %s CHECK ('.
            "(shift_type IN ('off', 'absence') AND store_id IS NULL AND start_time IS NULL) OR ".
            "(shift_type IN ('early', 'help') AND store_id IS NOT NULL AND start_time IS NULL) OR ".
            "(shift_type = 'time' AND store_id IS NOT NULL AND start_time IS NOT NULL ".
            'AND MINUTE(start_time) = 0 AND SECOND(start_time) = 0))',
            self::CONSTRAINT,
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('shifts')->where('shift_type', 'help')->update(['shift_type' => 'early']);
        DB::statement(sprintf('ALTER TABLE shifts DROP CHECK %s', self::CONSTRAINT));
        DB::statement(sprintf(
            'ALTER TABLE shifts ADD CONSTRAINT %s CHECK ('.
            "(shift_type IN ('off', 'absence') AND store_id IS NULL AND start_time IS NULL) OR ".
            "(shift_type = 'early' AND store_id IS NOT NULL AND start_time IS NULL) OR ".
            "(shift_type = 'time' AND store_id IS NOT NULL AND start_time IS NOT NULL ".
            'AND MINUTE(start_time) = 0 AND SECOND(start_time) = 0))',
            self::CONSTRAINT,
        ));
    }
};
