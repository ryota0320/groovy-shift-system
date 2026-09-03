<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_store_display_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staffs')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['store_id', 'staff_id']);
            $table->index(['store_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_store_display_orders');
    }
};
