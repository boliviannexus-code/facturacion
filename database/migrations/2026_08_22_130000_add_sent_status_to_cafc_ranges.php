<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sin_cafc_ranges DROP CONSTRAINT IF EXISTS sin_cafc_status_check');
        DB::statement("ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_status_check CHECK (range_status IN ('AVAILABLE','IN_USE','SENT','EXHAUSTED','EXPIRED','BLOCKED','CANCELLED'))");
        DB::statement(<<<'SQL'
            UPDATE sin_cafc_ranges AS ranges
            SET range_status = 'SENT', updated_at = CURRENT_TIMESTAMP
            WHERE ranges.sin_significant_event_id IS NOT NULL
              AND EXISTS (
                  SELECT 1 FROM sin_invoice_packages AS packages
                  WHERE packages.company_id = ranges.company_id
                    AND packages.sin_significant_event_id = ranges.sin_significant_event_id
                    AND packages.emission_mode = 'MANUAL_CAFC'
              )
              AND NOT EXISTS (
                  SELECT 1 FROM sin_invoice_packages AS packages
                  WHERE packages.company_id = ranges.company_id
                    AND packages.sin_significant_event_id = ranges.sin_significant_event_id
                    AND packages.emission_mode = 'MANUAL_CAFC'
                    AND packages.package_status IN ('CREATED', 'PENDING_SEND', 'FAILED')
              )
            SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE sin_cafc_ranges SET range_status = 'IN_USE' WHERE range_status = 'SENT'");
        DB::statement('ALTER TABLE sin_cafc_ranges DROP CONSTRAINT IF EXISTS sin_cafc_status_check');
        DB::statement("ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_status_check CHECK (range_status IN ('AVAILABLE','IN_USE','EXHAUSTED','EXPIRED','BLOCKED','CANCELLED'))");
    }
};
