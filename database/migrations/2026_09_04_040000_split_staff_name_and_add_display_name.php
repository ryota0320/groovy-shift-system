<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staffs', function (Blueprint $table): void {
            $table->string('last_name')->nullable()->after('name');
            $table->string('first_name')->nullable()->after('last_name');
            $table->string('display_name')->nullable()->after('first_name');
        });

        DB::table('staffs')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(100, function ($staffs): void {
                foreach ($staffs as $staff) {
                    $parts = preg_split('/[\s　]+/u', trim((string) $staff->name), 2) ?: [];
                    $lastName = $parts[0] ?? '';
                    $firstName = $parts[1] ?? '';
                    DB::table('staffs')->where('id', $staff->id)->update([
                        'name' => trim("{$lastName} {$firstName}"),
                        'last_name' => $lastName,
                        'first_name' => $firstName,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('staffs', function (Blueprint $table): void {
            $table->dropColumn(['last_name', 'first_name', 'display_name']);
        });
    }
};
