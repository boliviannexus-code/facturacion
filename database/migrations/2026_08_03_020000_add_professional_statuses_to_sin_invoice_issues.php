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
        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->string('emission_mode', 40)->default('ONLINE')->after('invoice_document_type_code');
            $table->string('commercial_status', 40)->default('CONFIRMED')->after('emission_mode');
            $table->string('fiscal_status', 50)->default('PENDING_ONLINE_SEND')->after('commercial_status');
            $table->string('failure_category', 60)->nullable()->after('fiscal_status');

            $table->index(['company_id', 'fiscal_status']);
            $table->index(['company_id', 'failure_category']);
        });

        DB::statement(<<<'SQL'
            UPDATE sin_invoice_issues
            SET fiscal_status = CASE
                WHEN status_code = 908 AND transaccion = true THEN 'VALIDATED'
                WHEN status_code = 904 THEN 'OBSERVED'
                WHEN status_label = 'Error' THEN 'PENDING_ONLINE_SEND'
                WHEN status_label = 'Pendiente' THEN 'PENDING_ONLINE_SEND'
                ELSE 'REJECTED'
            END
        SQL);
    }

    public function down(): void
    {
        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'fiscal_status']);
            $table->dropIndex(['company_id', 'failure_category']);
            $table->dropColumn(['emission_mode', 'commercial_status', 'fiscal_status', 'failure_category']);
        });
    }
};
