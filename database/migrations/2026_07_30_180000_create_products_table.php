<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->unique(['company_id', 'id']);
        });

        Schema::table('measurement_units', function (Blueprint $table): void {
            $table->unique(['company_id', 'id']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_category_id');
            $table->unsignedBigInteger('measurement_unit_id');
            $table->string('internal_code', 120);
            $table->string('description', 500);
            $table->string('economic_activity_code', 50);
            $table->unsignedBigInteger('siat_product_code');
            $table->decimal('unit_price', 20, 5);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['company_id', 'product_category_id'])
                ->references(['company_id', 'id'])
                ->on('product_categories')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'measurement_unit_id'])
                ->references(['company_id', 'id'])
                ->on('measurement_units')
                ->restrictOnDelete();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'product_category_id']);
            $table->index(['company_id', 'measurement_unit_id']);
            $table->index(['company_id', 'siat_product_code']);
            $table->index(['company_id', 'economic_activity_code']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX products_company_internal_code_unique
            ON products (company_id, lower(internal_code))
            WHERE deleted_at IS NULL'
        );
        DB::statement(
            'ALTER TABLE products
            ADD CONSTRAINT products_required_text_not_blank_check
            CHECK (
                length(trim(internal_code)) > 0
                AND length(trim(description)) > 0
                AND length(trim(economic_activity_code)) > 0
            )'
        );
        DB::statement(
            'ALTER TABLE products
            ADD CONSTRAINT products_siat_product_code_positive_check
            CHECK (siat_product_code > 0)'
        );
        DB::statement(
            'ALTER TABLE products
            ADD CONSTRAINT products_unit_price_non_negative_check
            CHECK (unit_price >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('products');

        Schema::table('measurement_units', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'id']);
        });

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'id']);
        });
    }
};
