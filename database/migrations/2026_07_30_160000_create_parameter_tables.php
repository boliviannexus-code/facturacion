<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'is_active']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX product_categories_company_name_unique
            ON product_categories (company_id, lower(name))
            WHERE deleted_at IS NULL'
        );
        DB::statement(
            'ALTER TABLE product_categories
            ADD CONSTRAINT product_categories_name_not_blank_check
            CHECK (length(trim(name)) > 0)'
        );

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

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('identity_document_type_code');
            $table->string('document_number', 80);
            $table->string('document_complement', 20)->nullable();
            $table->string('customer_code', 120);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX customers_company_customer_code_unique
            ON customers (company_id, lower(customer_code))
            WHERE deleted_at IS NULL'
        );
        DB::statement(
            "CREATE UNIQUE INDEX customers_company_document_unique
            ON customers (
                company_id,
                identity_document_type_code,
                lower(document_number),
                lower(coalesce(document_complement, ''))
            )
            WHERE deleted_at IS NULL"
        );
        DB::statement(
            'ALTER TABLE customers
            ADD CONSTRAINT customers_identity_document_type_code_positive_check
            CHECK (identity_document_type_code > 0)'
        );
        DB::statement(
            'ALTER TABLE customers
            ADD CONSTRAINT customers_required_text_not_blank_check
            CHECK (
                length(trim(document_number)) > 0
                AND length(trim(customer_code)) > 0
                AND length(trim(name)) > 0
            )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('measurement_units');
        Schema::dropIfExists('product_categories');
    }
};
