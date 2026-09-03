<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('payment_date');
            $table->unsignedSmallInteger('tax_year');
            $table->unsignedInteger('working_minutes')->default(0);
            $table->unsignedInteger('late_night_minutes')->default(0);
            $table->unsignedBigInteger('base_pay')->default(0);
            $table->unsignedBigInteger('late_night_pay')->default(0);
            $table->unsignedBigInteger('transportation_fee_total')->default(0);
            $table->unsignedBigInteger('transportation_fee_taxable')->default(0);
            $table->unsignedBigInteger('transportation_fee_non_taxable')->default(0);
            $table->unsignedBigInteger('commission')->default(0);
            $table->unsignedBigInteger('gross_pay')->default(0);
            $table->unsignedBigInteger('taxable_pay')->default(0);
            $table->unsignedBigInteger('social_insurance_deduction')->default(0);
            $table->unsignedBigInteger('tax_table_reference_amount')->default(0);
            $table->unsignedBigInteger('income_tax')->default(0);
            $table->unsignedBigInteger('other_deductions')->default(0);
            $table->unsignedBigInteger('total_deductions')->default(0);
            $table->bigInteger('net_pay')->default(0);
            $table->boolean('needs_recalculation')->default(false);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'year', 'month']);
            $table->index(['year', 'month', 'needs_recalculation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
