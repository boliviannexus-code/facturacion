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
        Schema::table('sin_invoice_packages', function (Blueprint $table): void {
            $table->unsignedBigInteger('sin_api_token_id')->nullable()->after('company_id');
            $table->unsignedBigInteger('sin_authorization_id')->nullable()->after('sin_api_token_id');
            $table->string('tax_id', 32)->nullable()->after('sin_cufd_id');
            $table->unsignedSmallInteger('environment_code')->nullable()->after('tax_id');
            $table->unsignedSmallInteger('modality_code')->nullable()->after('environment_code');
            $table->unsignedSmallInteger('emission_type_code')->nullable()->after('modality_code');
            $table->unsignedSmallInteger('document_sector_code')->nullable()->after('emission_type_code');
            $table->unsignedSmallInteger('invoice_document_type_code')->nullable()->after('document_sector_code');
            $table->unsignedSmallInteger('branch_code')->nullable()->after('invoice_document_type_code');
            $table->unsignedSmallInteger('point_of_sale_code')->nullable()->after('branch_code');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_hash');
            $table->uuid('send_claim')->nullable()->after('siat_status_code');
            $table->timestampTz('send_claimed_at')->nullable()->after('send_claim');
            $table->uuid('validation_claim')->nullable()->after('send_claimed_at');
            $table->timestampTz('validation_claimed_at')->nullable()->after('validation_claim');
            $table->unsignedInteger('validation_checks')->default(0)->after('validation_claimed_at');
            $table->timestampTz('last_validation_at')->nullable()->after('validated_at');

            $table->index(
                ['company_id', 'sin_significant_event_id', 'document_sector_code', 'invoice_document_type_code'],
                'sin_packages_event_document_index',
            );
            $table->index('send_claim');
            $table->index('validation_claim');
        });

        Schema::create('sin_invoice_package_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_branch_id');
            $table->unsignedBigInteger('sin_point_of_sale_id');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestampsTz();

            $table->unique(['company_id', 'sin_branch_id', 'sin_point_of_sale_id'], 'sin_package_sequences_scope_unique');
        });

        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_token_foreign FOREIGN KEY (company_id, sin_api_token_id) REFERENCES sin_api_tokens(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_authorization_foreign FOREIGN KEY (company_id, sin_authorization_id) REFERENCES sin_authorizations(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_count_limit_check CHECK (invoice_count BETWEEN 0 AND 500)');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_artifact_size_check CHECK ((file_path IS NULL AND file_hash IS NULL AND file_size IS NULL) OR (file_path IS NOT NULL AND file_hash IS NOT NULL AND file_size IS NOT NULL AND file_size > 0))');
        DB::statement('ALTER TABLE sin_invoice_package_sequences ADD CONSTRAINT sin_package_sequences_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_package_sequences ADD CONSTRAINT sin_package_sequences_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_generated_package_artifact_changes()
            RETURNS trigger AS $$
            DECLARE
                package_items_count integer;
            BEGIN
                IF OLD.file_path IS NULL AND NEW.file_path IS NOT NULL THEN
                    SELECT count(*) INTO package_items_count
                    FROM sin_invoice_package_items
                    WHERE company_id = NEW.company_id
                      AND sin_invoice_package_id = NEW.id;

                    IF package_items_count <> NEW.invoice_count THEN
                        RAISE EXCEPTION 'La cantidad declarada no coincide con las facturas del paquete.';
                    END IF;
                END IF;

                IF OLD.file_path IS NOT NULL AND (
                    NEW.file_path IS DISTINCT FROM OLD.file_path OR
                    NEW.file_hash IS DISTINCT FROM OLD.file_hash OR
                    NEW.file_size IS DISTINCT FROM OLD.file_size OR
                    NEW.invoice_count IS DISTINCT FROM OLD.invoice_count OR
                    NEW.sin_significant_event_id IS DISTINCT FROM OLD.sin_significant_event_id OR
                    NEW.company_id IS DISTINCT FROM OLD.company_id OR
                    NEW.sin_branch_id IS DISTINCT FROM OLD.sin_branch_id OR
                    NEW.sin_point_of_sale_id IS DISTINCT FROM OLD.sin_point_of_sale_id OR
                    NEW.sin_cuis_id IS DISTINCT FROM OLD.sin_cuis_id OR
                    NEW.sin_cufd_id IS DISTINCT FROM OLD.sin_cufd_id OR
                    NEW.tax_id IS DISTINCT FROM OLD.tax_id OR
                    NEW.environment_code IS DISTINCT FROM OLD.environment_code OR
                    NEW.modality_code IS DISTINCT FROM OLD.modality_code OR
                    NEW.emission_type_code IS DISTINCT FROM OLD.emission_type_code OR
                    NEW.document_sector_code IS DISTINCT FROM OLD.document_sector_code OR
                    NEW.invoice_document_type_code IS DISTINCT FROM OLD.invoice_document_type_code OR
                    NEW.branch_code IS DISTINCT FROM OLD.branch_code OR
                    NEW.point_of_sale_code IS DISTINCT FROM OLD.point_of_sale_code
                ) THEN
                    RAISE EXCEPTION 'El artefacto y alcance de un paquete generado son inmutables.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_generated_package_artifact_changes
            BEFORE UPDATE ON sin_invoice_packages
            FOR EACH ROW EXECUTE FUNCTION prevent_generated_package_artifact_changes();

            CREATE OR REPLACE FUNCTION enforce_invoice_package_item_scope()
            RETURNS trigger AS $$
            DECLARE
                package_row sin_invoice_packages%ROWTYPE;
                invoice_row sin_invoice_issues%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    SELECT * INTO package_row
                    FROM sin_invoice_packages
                    WHERE company_id = OLD.company_id AND id = OLD.sin_invoice_package_id;

                    IF package_row.file_path IS NOT NULL THEN
                        RAISE EXCEPTION 'Las facturas de un paquete generado son inmutables.';
                    END IF;

                    RETURN OLD;
                END IF;

                SELECT * INTO package_row
                FROM sin_invoice_packages
                WHERE company_id = NEW.company_id AND id = NEW.sin_invoice_package_id;
                SELECT * INTO invoice_row
                FROM sin_invoice_issues
                WHERE company_id = NEW.company_id AND id = NEW.sin_invoice_issue_id;

                IF package_row.id IS NULL OR invoice_row.id IS NULL THEN
                    RAISE EXCEPTION 'El paquete o la factura no existen dentro de la empresa.';
                END IF;

                IF package_row.file_path IS NOT NULL THEN
                    RAISE EXCEPTION 'Las facturas de un paquete generado son inmutables.';
                END IF;

                IF (invoice_row.sin_significant_event_id IS DISTINCT FROM package_row.sin_significant_event_id
                    AND NOT EXISTS (
                        SELECT 1 FROM sin_significant_events
                        WHERE company_id = package_row.company_id
                          AND id = package_row.sin_significant_event_id
                          AND sin_invoice_issue_id = invoice_row.id
                    ))
                    OR invoice_row.sin_branch_id IS DISTINCT FROM package_row.sin_branch_id
                    OR invoice_row.sin_point_of_sale_id IS DISTINCT FROM package_row.sin_point_of_sale_id
                    OR invoice_row.sin_cuis_id IS DISTINCT FROM package_row.sin_cuis_id
                    OR invoice_row.tax_id IS DISTINCT FROM package_row.tax_id
                    OR invoice_row.environment_code IS DISTINCT FROM package_row.environment_code
                    OR invoice_row.modality_code IS DISTINCT FROM package_row.modality_code
                    OR invoice_row.emission_type_code IS DISTINCT FROM package_row.emission_type_code
                    OR invoice_row.document_sector_code IS DISTINCT FROM package_row.document_sector_code
                    OR invoice_row.invoice_document_type_code IS DISTINCT FROM package_row.invoice_document_type_code
                    OR invoice_row.emission_mode <> 'OFFLINE_DIGITAL'
                    OR invoice_row.fiscal_status IN ('VALIDATED', 'VALIDATED_AFTER_CONTINGENCY', 'MANUAL_VALIDATED')
                    OR NEW.cuf IS DISTINCT FROM invoice_row.cuf THEN
                    RAISE EXCEPTION 'La factura no pertenece al alcance fiscal del paquete digital.';
                END IF;

                IF TG_OP = 'INSERT' AND (
                    SELECT count(*)
                    FROM sin_invoice_package_items
                    WHERE company_id = NEW.company_id
                      AND sin_invoice_package_id = NEW.sin_invoice_package_id
                ) >= LEAST(package_row.invoice_count, 500) THEN
                    RAISE EXCEPTION 'El paquete excede la cantidad declarada de facturas.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER enforce_invoice_package_item_scope
            BEFORE INSERT OR UPDATE OR DELETE ON sin_invoice_package_items
            FOR EACH ROW EXECUTE FUNCTION enforce_invoice_package_item_scope();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS enforce_invoice_package_item_scope ON sin_invoice_package_items');
        DB::statement('DROP FUNCTION IF EXISTS enforce_invoice_package_item_scope()');
        DB::statement('DROP TRIGGER IF EXISTS prevent_generated_package_artifact_changes ON sin_invoice_packages');
        DB::statement('DROP FUNCTION IF EXISTS prevent_generated_package_artifact_changes()');
        Schema::dropIfExists('sin_invoice_package_sequences');
        DB::statement('ALTER TABLE sin_invoice_packages DROP CONSTRAINT IF EXISTS sin_packages_artifact_size_check');
        DB::statement('ALTER TABLE sin_invoice_packages DROP CONSTRAINT IF EXISTS sin_packages_count_limit_check');
        DB::statement('ALTER TABLE sin_invoice_packages DROP CONSTRAINT IF EXISTS sin_packages_company_authorization_foreign');
        DB::statement('ALTER TABLE sin_invoice_packages DROP CONSTRAINT IF EXISTS sin_packages_company_token_foreign');

        Schema::table('sin_invoice_packages', function (Blueprint $table): void {
            $table->dropIndex('sin_packages_event_document_index');
            $table->dropIndex(['send_claim']);
            $table->dropIndex(['validation_claim']);
            $table->dropColumn([
                'sin_api_token_id', 'sin_authorization_id', 'tax_id', 'environment_code',
                'modality_code', 'emission_type_code', 'document_sector_code',
                'invoice_document_type_code', 'branch_code', 'point_of_sale_code',
                'file_size', 'send_claim', 'send_claimed_at', 'validation_claim',
                'validation_claimed_at', 'validation_checks', 'last_validation_at',
            ]);
        });
    }
};
