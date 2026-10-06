<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('measurement_unit_code')->nullable()->after('product_category_id');
        });

        DB::statement(
            'UPDATE products
            SET measurement_unit_code = measurement_units.siat_code
            FROM measurement_units
            WHERE products.company_id = measurement_units.company_id
                AND products.measurement_unit_id = measurement_units.id'
        );

        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['company_id', 'measurement_unit_id']);
            $table->dropIndex(['company_id', 'measurement_unit_id']);
            $table->dropColumn('measurement_unit_id');
            $table->index(['company_id', 'measurement_unit_code']);
        });

        DB::statement(
            'ALTER TABLE products
            ALTER COLUMN measurement_unit_code SET NOT NULL'
        );

        DB::statement(
            'ALTER TABLE products
            ADD CONSTRAINT products_measurement_unit_code_positive_check
            CHECK (measurement_unit_code > 0)'
        );

        Schema::dropIfExists('measurement_units');
    }

    public function down(): void
    {
        Schema::create('measurement_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('siat_code');
            $table->string('description');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('synchronized_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'is_active']);
            $table->unique(['company_id', 'id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX measurement_units_company_siat_code_unique
            ON measurement_units (company_id, siat_code)
            WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX measurement_units_company_description_unique
            ON measurement_units (company_id, lower(description))
            WHERE deleted_at IS NULL'
        );
        DB::statement(
            'ALTER TABLE measurement_units
            ADD CONSTRAINT measurement_units_siat_code_positive_check
            CHECK (siat_code > 0)'
        );
        DB::statement(
            'ALTER TABLE measurement_units
            ADD CONSTRAINT measurement_units_description_not_blank_check
            CHECK (length(trim(description)) > 0)'
        );

        DB::statement(
            "INSERT INTO measurement_units (company_id, siat_code, description, is_active, created_at, updated_at)
            SELECT DISTINCT company_id, measurement_unit_code, 'Unidad SIAT ' || measurement_unit_code, true, now(), now()
            FROM products"
        );

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('measurement_unit_id')->nullable()->after('product_category_id');
        });

        DB::statement(
            'UPDATE products
            SET measurement_unit_id = measurement_units.id
            FROM measurement_units
            WHERE products.company_id = measurement_units.company_id
                AND products.measurement_unit_code = measurement_units.siat_code'
        );

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'measurement_unit_code']);
        });

        DB::statement('ALTER TABLE products DROP CONSTRAINT products_measurement_unit_code_positive_check');
        DB::statement('ALTER TABLE products ALTER COLUMN measurement_unit_id SET NOT NULL');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('measurement_unit_code');
            $table->foreign(['company_id', 'measurement_unit_id'])
                ->references(['company_id', 'id'])
                ->on('measurement_units')
                ->restrictOnDelete();
            $table->index(['company_id', 'measurement_unit_id']);
        });
    }
};
