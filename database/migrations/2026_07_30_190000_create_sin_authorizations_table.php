<?php

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sin_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('tax_id', 30);
            $table->string('legal_name');
            $table->text('system_code');
            $table->unsignedSmallInteger('environment_code')->default(SiatEnvironment::TestingAndPilot->value);
            $table->unsignedSmallInteger('modality_code')->default(SiatModality::ComputerizedOnline->value);
            $table->unsignedInteger('branch_code')->default(0);
            $table->unsignedInteger('point_of_sale_code')->nullable();
            $table->timestampsTz();

            $table->unique('company_id');
            $table->index(['company_id', 'environment_code', 'modality_code']);
        });

        DB::statement(
            "ALTER TABLE sin_authorizations
            ADD CONSTRAINT sin_authorizations_tax_id_digits_check
            CHECK (tax_id ~ '^[0-9]+$')"
        );
        DB::statement(
            'ALTER TABLE sin_authorizations
            ADD CONSTRAINT sin_authorizations_required_text_not_blank_check
            CHECK (
                length(trim(tax_id)) > 0
                AND length(trim(legal_name)) > 0
                AND length(trim(system_code)) > 0
            )'
        );
        DB::statement(
            'ALTER TABLE sin_authorizations
            ADD CONSTRAINT sin_authorizations_environment_code_check
            CHECK (environment_code IN (1, 2))'
        );
        DB::statement(
            'ALTER TABLE sin_authorizations
            ADD CONSTRAINT sin_authorizations_modality_code_check
            CHECK (modality_code IN (1, 2))'
        );
        DB::statement(
            'ALTER TABLE sin_authorizations
            ADD CONSTRAINT sin_authorizations_branch_and_pos_non_negative_check
            CHECK (
                branch_code >= 0
                AND (point_of_sale_code IS NULL OR point_of_sale_code >= 0)
            )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_authorizations');
    }
};
