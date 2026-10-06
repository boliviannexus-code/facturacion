<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_manual_contingency_invoices', function (Blueprint $table): void {
            $table->boolean('retired_for_tests')->default(false);
        });

        // Previous test retirements preserved the invoices but did not release their numbers.
        DB::statement(<<<'SQL'
            UPDATE sin_manual_contingency_invoices AS manual
            SET retired_for_tests = true
            FROM sin_cafc_ranges AS cafc
            WHERE cafc.id = manual.sin_cafc_range_id
              AND cafc.deleted_at IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM sin_invoice_issues AS invoice
                  JOIN sin_manual_contingency_invoices AS related ON related.sin_invoice_issue_id = invoice.id
                  WHERE related.sin_cafc_range_id = cafc.id AND invoice.environment_code <> 2
              )
            SQL);
        DB::statement('DROP INDEX sin_manual_fiscal_number_unique');
        DB::statement('CREATE UNIQUE INDEX sin_manual_fiscal_number_unique ON sin_manual_contingency_invoices(company_id, sin_branch_id, sin_point_of_sale_id, document_sector_code, manual_invoice_number) WHERE is_test_copy = false AND retired_for_tests = false');
    }

    public function down(): void
    {
        if (DB::table('sin_manual_contingency_invoices')->where('retired_for_tests', true)->exists()) {
            throw new RuntimeException('No puede revertirse mientras existan números manuales retirados de pruebas.');
        }
        DB::statement('DROP INDEX sin_manual_fiscal_number_unique');
        DB::statement('CREATE UNIQUE INDEX sin_manual_fiscal_number_unique ON sin_manual_contingency_invoices(company_id, sin_branch_id, sin_point_of_sale_id, document_sector_code, manual_invoice_number) WHERE is_test_copy = false');
        Schema::table('sin_manual_contingency_invoices', function (Blueprint $table): void {
            $table->dropColumn('retired_for_tests');
        });
    }
};
