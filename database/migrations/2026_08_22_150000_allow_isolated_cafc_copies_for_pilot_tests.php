<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_cafc_ranges', function (Blueprint $table): void {
            $table->boolean('is_test_copy')->default(false);
            $table->foreignId('source_sin_cafc_range_id')->nullable()->constrained('sin_cafc_ranges')->restrictOnDelete();
        });
        DB::statement('DROP INDEX IF EXISTS sin_cafc_pos_authorization_unique');
        DB::statement('DROP INDEX IF EXISTS sin_cafc_branch_authorization_unique');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_pos_authorization_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id, sin_point_of_sale_id) WHERE sin_point_of_sale_id IS NOT NULL AND is_test_copy = false');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_branch_authorization_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id) WHERE sin_point_of_sale_id IS NULL AND is_test_copy = false');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM sin_cafc_ranges WHERE is_test_copy = true');
        Schema::table('sin_cafc_ranges', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_sin_cafc_range_id');
            $table->dropColumn('is_test_copy');
        });
    }
};
