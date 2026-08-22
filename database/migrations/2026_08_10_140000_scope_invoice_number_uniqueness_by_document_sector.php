<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_id_invoice_number_unique');
        DB::statement(
            'CREATE UNIQUE INDEX sin_invoice_issues_company_sector_invoice_number_unique
            ON sin_invoice_issues (company_id, document_sector_code, invoice_number)
            WHERE invoice_number IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_sector_invoice_number_unique');
        DB::statement(
            'CREATE UNIQUE INDEX sin_invoice_issues_company_id_invoice_number_unique
            ON sin_invoice_issues (company_id, invoice_number)
            WHERE invoice_number IS NOT NULL'
        );
    }
};
