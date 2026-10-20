<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_cafc_ranges', function (Blueprint $table): void {
            $table->softDeletes();
        });
        DB::statement('DROP INDEX sin_cafc_pos_authorization_unique');
        DB::statement('DROP INDEX sin_cafc_branch_authorization_unique');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_pos_authorization_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id, sin_point_of_sale_id) WHERE sin_point_of_sale_id IS NOT NULL AND is_test_copy = false AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_branch_authorization_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id) WHERE sin_point_of_sale_id IS NULL AND is_test_copy = false AND deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::table('sin_cafc_ranges')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('No puede revertirse esta migración mientras existan CAFC retirados de pruebas.');
        }
        DB::statement('DROP INDEX sin_cafc_pos_authorization_unique');
        DB::statement('DROP INDEX sin_cafc_branch_authorization_unique');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_pos_authorization_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id, sin_point_of_sale_id) WHERE sin_point_of_sale_id IS NOT NULL AND is_test_copy = false');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_branch_authorization_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id) WHERE sin_point_of_sale_id IS NULL AND is_test_copy = false');
        Schema::table('sin_cafc_ranges', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
