<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_shift_staff_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staffs')->cascadeOnDelete();
            $table->date('month');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['store_id', 'staff_id', 'month']);
            $table->index(['store_id', 'month', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_shift_staff_additions');
    }
};
