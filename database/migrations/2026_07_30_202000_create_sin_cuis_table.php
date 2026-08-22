<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sin_cuis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sin_api_token_id')->nullable()->constrained('sin_api_tokens')->nullOnDelete();
            $table->foreignId('sin_authorization_id')->nullable()->constrained('sin_authorizations')->nullOnDelete();
            $table->string('tax_id', 30);
            $table->string('wsdl_url', 2048);
            $table->unsignedSmallInteger('environment_code');
            $table->unsignedSmallInteger('modality_code');
            $table->unsignedInteger('branch_code');
            $table->unsignedInteger('point_of_sale_code')->default(0);
            $table->boolean('transaccion')->default(false);
            $table->string('cuis_code', 128)->nullable();
            $table->text('message')->nullable();
            $table->jsonb('response')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('requested_at');
            $table->timestampsTz();

            $table->index(['company_id', 'requested_at']);
            $table->index(['company_id', 'transaccion']);
            $table->index(['company_id', 'cuis_code']);
        });

        DB::statement(
            "ALTER TABLE sin_cuis
            ADD CONSTRAINT sin_cuis_tax_id_digits_check
            CHECK (tax_id ~ '^[0-9]+$')"
        );
        DB::statement(
            'ALTER TABLE sin_cuis
            ADD CONSTRAINT sin_cuis_required_text_not_blank_check
            CHECK (
                length(trim(tax_id)) > 0
                AND length(trim(wsdl_url)) > 0
            )'
        );
        DB::statement(
            'ALTER TABLE sin_cuis
            ADD CONSTRAINT sin_cuis_environment_code_check
            CHECK (environment_code IN (1, 2))'
        );
        DB::statement(
            'ALTER TABLE sin_cuis
            ADD CONSTRAINT sin_cuis_modality_code_check
            CHECK (modality_code IN (1, 2))'
        );
        DB::statement(
            'ALTER TABLE sin_cuis
            ADD CONSTRAINT sin_cuis_success_requires_code_check
            CHECK (
                transaccion = false
                OR (cuis_code IS NOT NULL AND length(trim(cuis_code)) > 0)
            )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_cuis');
    }
};
