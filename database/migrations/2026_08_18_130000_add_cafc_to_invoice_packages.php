<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_invoice_packages', function (Blueprint $table): void {
            $table->string('cafc_code', 128)->nullable()->after('invoice_count');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_invoice_package_item_scope()
            RETURNS trigger AS $$
            DECLARE
                package_row sin_invoice_packages%ROWTYPE;
                invoice_row sin_invoice_issues%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    SELECT * INTO package_row FROM sin_invoice_packages WHERE company_id = OLD.company_id AND id = OLD.sin_invoice_package_id;
                    IF package_row.file_path IS NOT NULL THEN RAISE EXCEPTION 'Las facturas de un paquete generado son inmutables.'; END IF;
                    RETURN OLD;
                END IF;

                SELECT * INTO package_row FROM sin_invoice_packages WHERE company_id = NEW.company_id AND id = NEW.sin_invoice_package_id;
                SELECT * INTO invoice_row FROM sin_invoice_issues WHERE company_id = NEW.company_id AND id = NEW.sin_invoice_issue_id;

                IF package_row.id IS NULL OR invoice_row.id IS NULL THEN RAISE EXCEPTION 'El paquete o la factura no existen dentro de la empresa.'; END IF;
                IF package_row.file_path IS NOT NULL THEN RAISE EXCEPTION 'Las facturas de un paquete generado son inmutables.'; END IF;

                IF (invoice_row.sin_significant_event_id IS DISTINCT FROM package_row.sin_significant_event_id
                    AND NOT EXISTS (SELECT 1 FROM sin_significant_events WHERE company_id = package_row.company_id AND id = package_row.sin_significant_event_id AND sin_invoice_issue_id = invoice_row.id))
                    OR invoice_row.sin_branch_id IS DISTINCT FROM package_row.sin_branch_id
                    OR invoice_row.sin_point_of_sale_id IS DISTINCT FROM package_row.sin_point_of_sale_id
                    OR invoice_row.sin_cuis_id IS DISTINCT FROM package_row.sin_cuis_id
                    OR invoice_row.tax_id IS DISTINCT FROM package_row.tax_id
                    OR invoice_row.environment_code IS DISTINCT FROM package_row.environment_code
                    OR invoice_row.modality_code IS DISTINCT FROM package_row.modality_code
                    OR invoice_row.emission_type_code IS DISTINCT FROM package_row.emission_type_code
                    OR invoice_row.document_sector_code IS DISTINCT FROM package_row.document_sector_code
                    OR invoice_row.invoice_document_type_code IS DISTINCT FROM package_row.invoice_document_type_code
                    OR invoice_row.emission_mode IS DISTINCT FROM package_row.emission_mode
                    OR invoice_row.emission_mode NOT IN ('OFFLINE_DIGITAL', 'MANUAL_CAFC')
                    OR invoice_row.fiscal_status IN ('VALIDATED', 'VALIDATED_AFTER_CONTINGENCY', 'MANUAL_VALIDATED')
                    OR NEW.cuf IS DISTINCT FROM invoice_row.cuf
                    OR (invoice_row.emission_mode = 'MANUAL_CAFC' AND ((to_jsonb(package_row)->>'cafc_code') IS NULL OR NOT EXISTS (
                        SELECT 1 FROM sin_manual_contingency_invoices manual
                        JOIN sin_cafc_ranges cafc ON cafc.company_id = manual.company_id AND cafc.id = manual.sin_cafc_range_id
                        WHERE manual.company_id = invoice_row.company_id AND manual.sin_invoice_issue_id = invoice_row.id AND cafc.cafc_code = (to_jsonb(package_row)->>'cafc_code')
                    )))
                    OR (invoice_row.emission_mode = 'OFFLINE_DIGITAL' AND (to_jsonb(package_row)->>'cafc_code') IS NOT NULL) THEN
                    RAISE EXCEPTION 'La factura no pertenece al alcance fiscal del paquete digital o CAFC.';
                END IF;

                IF TG_OP = 'INSERT' AND (SELECT count(*) FROM sin_invoice_package_items WHERE company_id = NEW.company_id AND sin_invoice_package_id = NEW.sin_invoice_package_id) >= LEAST(package_row.invoice_count, 500) THEN
                    RAISE EXCEPTION 'El paquete excede la cantidad declarada de facturas.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE sin_invoice_packages SET cafc_code = NULL WHERE emission_mode = 'MANUAL_CAFC'");
        Schema::table('sin_invoice_packages', fn (Blueprint $table) => $table->dropColumn('cafc_code'));
    }
};
