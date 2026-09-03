<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('store_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->date('holiday_date');
            $table->timestamps();

            $table->unique(['store_id', 'holiday_date']);
            $table->index(['holiday_date', 'store_id']);
        });

        Schema::create('staffs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('employment_type')->index();
            $table->date('hired_at')->nullable()->index();
            $table->date('retired_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('staff_id')
                ->nullable()
                ->after('id')
                ->unique()
                ->constrained('staffs')
                ->restrictOnDelete();
        });

        Schema::create('staff_store_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(
                ['staff_id', 'effective_from', 'effective_to'],
                'staff_assignment_effective_idx',
            );
            $table->index(
                ['store_id', 'effective_from', 'effective_to'],
                'store_assignment_effective_idx',
            );
        });

        Schema::create('staff_wage_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();
            $table->unsignedInteger('hourly_wage');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(
                ['staff_id', 'effective_from', 'effective_to'],
                'staff_wage_effective_idx',
            );
        });

        Schema::create('staff_store_transportation_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount_per_day');
            $table->string('tax_type');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(
                ['staff_id', 'store_id', 'effective_from', 'effective_to'],
                'staff_transport_effective_idx',
            );
        });

        Schema::create('staff_income_tax_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();
            $table->string('tax_category');
            $table->unsignedSmallInteger('dependent_count');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(
                ['staff_id', 'effective_from', 'effective_to'],
                'staff_tax_effective_idx',
            );
        });

        Schema::create('late_night_rate_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('amount_per_hour');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(
                ['effective_from', 'effective_to'],
                'late_night_rate_effective_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_night_rate_settings');
        Schema::dropIfExists('staff_income_tax_settings');
        Schema::dropIfExists('staff_store_transportation_fees');
        Schema::dropIfExists('staff_wage_rates');
        Schema::dropIfExists('staff_store_assignments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_id');
        });

        Schema::dropIfExists('staffs');
        Schema::dropIfExists('store_holidays');
        Schema::dropIfExists('stores');
    }
};
