<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sin_significant_events ALTER COLUMN sin_invoice_issue_id DROP NOT NULL');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_significant_events_sin_invoice_issue_id_foreign');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_significant_events_sin_invoice_issue_id_foreign FOREIGN KEY (sin_invoice_issue_id) REFERENCES sin_invoice_issues(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_significant_events_sin_invoice_issue_id_foreign');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_significant_events_sin_invoice_issue_id_foreign FOREIGN KEY (sin_invoice_issue_id) REFERENCES sin_invoice_issues(id) ON DELETE RESTRICT');
    }
};
