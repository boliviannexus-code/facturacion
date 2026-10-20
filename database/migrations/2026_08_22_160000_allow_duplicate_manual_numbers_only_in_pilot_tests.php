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
        Schema::table('sin_manual_contingency_invoices', function (Blueprint $table): void {
            $table->boolean('is_test_copy')->default(false);
        });
        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_fiscal_number_unique');
        DB::statement('CREATE UNIQUE INDEX sin_manual_fiscal_number_unique ON sin_manual_contingency_invoices(company_id, sin_branch_id, sin_point_of_sale_id, document_sector_code, manual_invoice_number) WHERE is_test_copy = false');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM sin_manual_contingency_invoices WHERE is_test_copy = true');
        DB::statement('DROP INDEX IF EXISTS sin_manual_fiscal_number_unique');
        Schema::table('sin_manual_contingency_invoices', function (Blueprint $table): void {
            $table->dropColumn('is_test_copy');
        });
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_fiscal_number_unique UNIQUE(company_id, sin_branch_id, sin_point_of_sale_id, document_sector_code, manual_invoice_number)');
    }
};
