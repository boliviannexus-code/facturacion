<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sin_invoice_issues')) {
            return;
        }

        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            if (! Schema::hasColumn('sin_invoice_issues', 'attempted_invoice_number')) {
                $table->unsignedBigInteger('attempted_invoice_number')->nullable()->after('point_of_sale_code');
            }
        });

        DB::statement('UPDATE sin_invoice_issues SET attempted_invoice_number = invoice_number WHERE attempted_invoice_number IS NULL');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoice_issues_company_id_invoice_number_unique');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoice_issues_company_id_cuf_unique');
        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_id_invoice_number_unique');
        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_id_cuf_unique');
        DB::statement('ALTER TABLE sin_invoice_issues ALTER COLUMN invoice_number DROP NOT NULL');
        DB::statement('UPDATE sin_invoice_issues SET invoice_number = NULL WHERE status_code IS DISTINCT FROM 908 OR transaccion IS DISTINCT FROM TRUE');
        DB::statement('ALTER TABLE sin_invoice_issues ALTER COLUMN attempted_invoice_number SET NOT NULL');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS sin_invoice_issues_company_id_invoice_number_unique
            ON sin_invoice_issues (company_id, invoice_number)
            WHERE invoice_number IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS sin_invoice_issues_company_id_cuf_unique
            ON sin_invoice_issues (company_id, cuf)
            WHERE invoice_number IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('sin_invoice_issues')) {
            return;
        }

        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoice_issues_company_id_invoice_number_unique');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoice_issues_company_id_cuf_unique');
        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_id_invoice_number_unique');
        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_id_cuf_unique');
        DB::statement('UPDATE sin_invoice_issues SET invoice_number = attempted_invoice_number WHERE invoice_number IS NULL');
        DB::statement('ALTER TABLE sin_invoice_issues ALTER COLUMN invoice_number SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX sin_invoice_issues_company_id_invoice_number_unique ON sin_invoice_issues (company_id, invoice_number)');
        DB::statement('CREATE UNIQUE INDEX sin_invoice_issues_company_id_cuf_unique ON sin_invoice_issues (company_id, cuf)');

        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            if (Schema::hasColumn('sin_invoice_issues', 'attempted_invoice_number')) {
                $table->dropColumn('attempted_invoice_number');
            }
        });
    }
};
