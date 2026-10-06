<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sin_invoice_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sin_api_token_id')->nullable()->constrained('sin_api_tokens')->nullOnDelete();
            $table->foreignId('sin_authorization_id')->nullable()->constrained('sin_authorizations')->nullOnDelete();
            $table->foreignId('sin_branch_id')->nullable()->constrained('sin_branches')->nullOnDelete();
            $table->foreignId('sin_point_of_sale_id')->nullable()->constrained('sin_points_of_sale')->nullOnDelete();
            $table->foreignId('sin_cuis_id')->nullable()->constrained('sin_cuis')->nullOnDelete();
            $table->foreignId('sin_cufd_id')->nullable()->constrained('sin_cufds')->nullOnDelete();
            $table->string('tax_id', 30);
            $table->unsignedSmallInteger('environment_code');
            $table->unsignedSmallInteger('modality_code');
            $table->unsignedSmallInteger('emission_type_code')->default(1);
            $table->unsignedSmallInteger('document_sector_code')->default(1);
            $table->unsignedSmallInteger('invoice_document_type_code')->default(1);
            $table->unsignedInteger('branch_code');
            $table->unsignedInteger('point_of_sale_code')->default(0);
            $table->unsignedBigInteger('attempted_invoice_number');
            $table->unsignedBigInteger('invoice_number')->nullable();
            $table->string('cuf', 256);
            $table->string('cufd_code', 256);
            $table->string('control_code', 128)->nullable();
            $table->string('reception_code', 128)->nullable();
            $table->unsignedInteger('status_code')->nullable();
            $table->string('status_label', 80)->default('Pendiente');
            $table->boolean('transaccion')->default(false);
            $table->string('xml_path')->nullable();
            $table->string('gzip_path')->nullable();
            $table->string('hash_file', 64)->nullable();
            $table->decimal('subtotal_amount', 18, 5)->default(0);
            $table->decimal('discount_amount', 18, 5)->default(0);
            $table->decimal('total_amount', 18, 5)->default(0);
            $table->decimal('taxable_amount', 18, 5)->default(0);
            $table->jsonb('payload')->nullable();
            $table->jsonb('response')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('issued_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'issued_at']);
            $table->index(['company_id', 'status_code']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX sin_invoice_issues_company_id_invoice_number_unique
            ON sin_invoice_issues (company_id, invoice_number)
            WHERE invoice_number IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX sin_invoice_issues_company_id_cuf_unique
            ON sin_invoice_issues (company_id, cuf)
            WHERE invoice_number IS NOT NULL'
        );

        DB::statement(
            "ALTER TABLE sin_invoice_issues
            ADD CONSTRAINT sin_invoice_issues_tax_id_digits_check
            CHECK (tax_id ~ '^[0-9]+$')"
        );
        DB::statement(
            'ALTER TABLE sin_invoice_issues
            ADD CONSTRAINT sin_invoice_issues_required_text_not_blank_check
            CHECK (
                length(trim(tax_id)) > 0
                AND length(trim(cuf)) > 0
                AND length(trim(cufd_code)) > 0
                AND length(trim(status_label)) > 0
            )'
        );
        DB::statement(
            'ALTER TABLE sin_invoice_issues
            ADD CONSTRAINT sin_invoice_issues_totals_non_negative_check
            CHECK (
                subtotal_amount >= 0
                AND discount_amount >= 0
                AND total_amount >= 0
                AND taxable_amount >= 0
            )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_invoice_issues');
    }
};
