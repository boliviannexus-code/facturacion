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
        DB::statement('ALTER TABLE sin_cafc_ranges DROP CONSTRAINT IF EXISTS sin_cafc_status_check');
        DB::statement("UPDATE sin_cafc_ranges SET range_status = 'BLOCKED' WHERE range_status = 'SUSPENDED'");
        DB::statement('ALTER TABLE sin_cafc_ranges ALTER COLUMN sin_point_of_sale_id DROP NOT NULL');
        DB::statement('ALTER TABLE sin_cafc_ranges DROP CONSTRAINT IF EXISTS sin_cafc_authorization_scope_unique');
        DB::statement('DROP INDEX IF EXISTS sin_cafc_authorization_scope_unique');

        Schema::table('sin_cafc_ranges', function (Blueprint $table): void {
            $table->unsignedBigInteger('used_count')->default(0);
            $table->unsignedBigInteger('cancelled_count')->default(0);
        });

        DB::statement("ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_status_check CHECK (range_status IN ('AVAILABLE','IN_USE','EXHAUSTED','EXPIRED','BLOCKED','CANCELLED'))");
        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_usage_counts_check CHECK (used_count >= 0 AND cancelled_count >= 0 AND used_count + cancelled_count <= range_end - range_start + 1)');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_pos_authorization_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id, sin_point_of_sale_id) WHERE sin_point_of_sale_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_branch_authorization_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id) WHERE sin_point_of_sale_id IS NULL');

        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_company_invoice_foreign');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ALTER COLUMN sin_invoice_issue_id DROP NOT NULL');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_invoice_foreign FOREIGN KEY (company_id, sin_invoice_issue_id) REFERENCES sin_invoice_issues(company_id, id) ON DELETE RESTRICT');

        Schema::table('sin_manual_contingency_invoices', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->unsignedSmallInteger('identity_document_type_code')->nullable();
            $table->string('document_number', 50)->nullable();
            $table->string('document_complement', 20)->nullable();
            $table->string('customer_code', 100)->nullable();
            $table->unsignedSmallInteger('payment_method_code')->nullable();
            $table->unsignedSmallInteger('currency_code')->nullable();
            $table->decimal('subtotal_amount', 18, 5)->default(0);
            $table->decimal('discount_amount', 18, 5)->default(0);
            $table->decimal('total_amount', 18, 5)->default(0);
            $table->text('observations')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('xml_hash', 64)->nullable();
            $table->unique(['company_id', 'id'], 'sin_manual_company_id_unique');
        });

        DB::statement("UPDATE sin_manual_contingency_invoices SET void_reason = COALESCE(void_reason, 'Anulación migrada'), voided_at = COALESCE(voided_at, updated_at, now()), voided_by_user_id = COALESCE(voided_by_user_id, transcribed_by_user_id, created_by_user_id, (SELECT id FROM users WHERE users.company_id = sin_manual_contingency_invoices.company_id ORDER BY id LIMIT 1)) WHERE manual_status = 'CANCELLED'");

        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_status_check');
        DB::statement("UPDATE sin_manual_contingency_invoices SET manual_status = 'PENDING_TRANSCRIPTION' WHERE manual_status IN ('RESERVED','ISSUED')");
        DB::statement("ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_status_check CHECK (manual_status IN ('PENDING_TRANSCRIPTION','TRANSCRIBED','PENDING_SEND','VALIDATED','REJECTED','CANCELLED'))");
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_totals_check CHECK (subtotal_amount >= 0 AND discount_amount >= 0 AND total_amount >= 0 AND total_amount = subtotal_amount - discount_amount)');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_void_check CHECK ((manual_status = \'CANCELLED\' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND length(trim(void_reason)) > 0) OR (manual_status <> \'CANCELLED\' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL))');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_xml_artifact_check CHECK ((xml_path IS NULL AND xml_hash IS NULL) OR (xml_path IS NOT NULL AND xml_hash IS NOT NULL))');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_customer_foreign FOREIGN KEY (company_id, customer_id) REFERENCES customers(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_voider_foreign FOREIGN KEY (company_id, voided_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX sin_manual_event_status_index ON sin_manual_contingency_invoices(company_id, sin_significant_event_id, manual_status)');

        Schema::create('sin_manual_contingency_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_manual_contingency_invoice_id');
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('economic_activity_code', 32);
            $table->unsignedBigInteger('siat_product_code');
            $table->string('internal_code', 100);
            $table->text('description');
            $table->unsignedInteger('measurement_unit_code');
            $table->decimal('quantity', 18, 5);
            $table->decimal('unit_price', 18, 5);
            $table->decimal('discount_amount', 18, 5)->default(0);
            $table->decimal('subtotal_amount', 18, 5);
            $table->timestampsTz();

            $table->unique(['company_id', 'id']);
            $table->unique(['sin_manual_contingency_invoice_id', 'line_number'], 'sin_manual_item_line_unique');
            $table->index(['company_id', 'sin_manual_contingency_invoice_id'], 'sin_manual_items_invoice_index');
        });

        DB::statement('ALTER TABLE sin_manual_contingency_invoice_items ADD CONSTRAINT sin_manual_items_company_invoice_foreign FOREIGN KEY (company_id, sin_manual_contingency_invoice_id) REFERENCES sin_manual_contingency_invoices(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoice_items ADD CONSTRAINT sin_manual_items_company_product_foreign FOREIGN KEY (company_id, product_id) REFERENCES products(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoice_items ADD CONSTRAINT sin_manual_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND subtotal_amount >= 0 AND subtotal_amount = round(quantity * unit_price - discount_amount, 5))');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_manual_contingency_invoice()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Las facturas manuales utilizadas o anuladas no pueden eliminarse';
                END IF;

                IF OLD.company_id IS DISTINCT FROM NEW.company_id
                    OR OLD.sin_cafc_range_id IS DISTINCT FROM NEW.sin_cafc_range_id
                    OR OLD.sin_branch_id IS DISTINCT FROM NEW.sin_branch_id
                    OR OLD.sin_point_of_sale_id IS DISTINCT FROM NEW.sin_point_of_sale_id
                    OR OLD.manual_invoice_number IS DISTINCT FROM NEW.manual_invoice_number
                    OR OLD.document_sector_code IS DISTINCT FROM NEW.document_sector_code
                    OR OLD.issued_manually_at IS DISTINCT FROM NEW.issued_manually_at THEN
                    RAISE EXCEPTION 'La identidad y fecha original de una factura manual son inmutables';
                END IF;

                IF OLD.xml_hash IS NOT NULL AND (OLD.xml_hash IS DISTINCT FROM NEW.xml_hash OR OLD.xml_path IS DISTINCT FROM NEW.xml_path) THEN
                    RAISE EXCEPTION 'El XML fiscal de una factura manual es inmutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER sin_manual_invoice_immutable BEFORE UPDATE OR DELETE ON sin_manual_contingency_invoices FOR EACH ROW EXECUTE FUNCTION protect_manual_contingency_invoice()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sin_manual_invoice_immutable ON sin_manual_contingency_invoices');
        DB::statement('DROP FUNCTION IF EXISTS protect_manual_contingency_invoice');
        Schema::dropIfExists('sin_manual_contingency_invoice_items');
        DB::statement('DELETE FROM sin_manual_contingency_invoices WHERE sin_invoice_issue_id IS NULL');

        DB::statement('DROP INDEX IF EXISTS sin_manual_event_status_index');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_company_id_unique');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_company_voider_foreign');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_company_customer_foreign');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_xml_artifact_check');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_void_check');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_totals_check');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices DROP CONSTRAINT IF EXISTS sin_manual_status_check');
        DB::statement("UPDATE sin_manual_contingency_invoices SET manual_status = 'TRANSCRIBED' WHERE manual_status IN ('REJECTED')");
        DB::statement("ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_status_check CHECK (manual_status IN ('RESERVED','ISSUED','PENDING_TRANSCRIPTION','TRANSCRIBED','PENDING_SEND','VALIDATED','CANCELLED'))");

        Schema::table('sin_manual_contingency_invoices', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['voided_by_user_id']);
            $table->dropColumn([
                'customer_id', 'voided_by_user_id', 'customer_name', 'identity_document_type_code',
                'document_number', 'document_complement', 'customer_code', 'payment_method_code',
                'currency_code', 'subtotal_amount', 'discount_amount', 'total_amount', 'observations',
                'void_reason', 'voided_at', 'xml_path', 'xml_hash',
            ]);
        });
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ALTER COLUMN sin_invoice_issue_id SET NOT NULL');

        DB::statement('DROP INDEX IF EXISTS sin_cafc_branch_authorization_unique');
        DB::statement('DROP INDEX IF EXISTS sin_cafc_pos_authorization_unique');
        DB::statement('ALTER TABLE sin_cafc_ranges DROP CONSTRAINT IF EXISTS sin_cafc_usage_counts_check');
        DB::statement('ALTER TABLE sin_cafc_ranges DROP CONSTRAINT IF EXISTS sin_cafc_status_check');
        DB::statement("UPDATE sin_cafc_ranges SET range_status = 'SUSPENDED' WHERE range_status IN ('IN_USE','BLOCKED','CANCELLED')");
        DB::statement("ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_status_check CHECK (range_status IN ('AVAILABLE','EXHAUSTED','SUSPENDED','EXPIRED'))");
        Schema::table('sin_cafc_ranges', fn (Blueprint $table) => $table->dropColumn(['used_count', 'cancelled_count']));
        DB::statement('UPDATE sin_cafc_ranges SET sin_point_of_sale_id = (SELECT id FROM sin_points_of_sale WHERE sin_points_of_sale.sin_branch_id = sin_cafc_ranges.sin_branch_id ORDER BY is_default DESC, id LIMIT 1) WHERE sin_point_of_sale_id IS NULL');
        DB::statement('DELETE FROM sin_manual_contingency_invoices WHERE sin_cafc_range_id IN (SELECT id FROM sin_cafc_ranges WHERE sin_point_of_sale_id IS NULL)');
        DB::statement('DELETE FROM sin_cafc_ranges WHERE sin_point_of_sale_id IS NULL');
        DB::statement('ALTER TABLE sin_cafc_ranges ALTER COLUMN sin_point_of_sale_id SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX sin_cafc_authorization_scope_unique ON sin_cafc_ranges(company_id, cafc_code, document_sector_code, sin_branch_id, sin_point_of_sale_id)');
    }
};
