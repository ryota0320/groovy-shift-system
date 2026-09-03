<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staffs')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->unique(['staff_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });

        Schema::create('income_tax_table_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('tax_year')->unique();
            $table->string('name');
            $table->string('source_url');
            $table->char('source_hash', 64);
            $table->timestamp('imported_at');
            $table->timestamps();
        });

        Schema::create('income_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_version_id')
                ->constrained('income_tax_table_versions')
                ->cascadeOnDelete();
            $table->string('tax_category');
            $table->unsignedTinyInteger('dependent_count')->nullable();
            $table->unsignedBigInteger('min_amount');
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->string('calculation_type');
            $table->unsignedBigInteger('fixed_tax_amount')->nullable();
            $table->json('parameters')->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(
                ['table_version_id', 'tax_category', 'dependent_count', 'min_amount'],
                'income_tax_rule_unique',
            );
            $table->index(
                ['table_version_id', 'tax_category', 'dependent_count', 'min_amount'],
                'income_tax_rule_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_tax_rules');
        Schema::dropIfExists('income_tax_table_versions');
        Schema::dropIfExists('commissions');
    }
};
