<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sin_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('branch_code');
            $table->string('name');
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'branch_code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('sin_points_of_sale', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sin_branch_id')->constrained('sin_branches')->cascadeOnDelete();
            $table->unsignedInteger('point_of_sale_code');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'sin_branch_id', 'point_of_sale_code']);
            $table->index(['company_id', 'is_active']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX sin_branches_company_main_unique
            ON sin_branches (company_id)
            WHERE is_main = true'
        );
        DB::statement(
            'CREATE UNIQUE INDEX sin_points_of_sale_branch_default_unique
            ON sin_points_of_sale (company_id, sin_branch_id)
            WHERE is_default = true'
        );
        DB::statement(
            'ALTER TABLE sin_branches
            ADD CONSTRAINT sin_branches_main_code_check
            CHECK (
                (is_main = true AND branch_code = 0)
                OR (is_main = false AND branch_code > 0)
            )'
        );
        DB::statement(
            'ALTER TABLE sin_branches
            ADD CONSTRAINT sin_branches_required_text_not_blank_check
            CHECK (length(trim(name)) > 0)'
        );
        DB::statement(
            'ALTER TABLE sin_points_of_sale
            ADD CONSTRAINT sin_points_of_sale_required_text_not_blank_check
            CHECK (length(trim(name)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_points_of_sale');
        Schema::dropIfExists('sin_branches');
    }
};
