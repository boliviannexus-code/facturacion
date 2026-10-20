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
        Schema::table('products', fn (Blueprint $table) => $table->unique(['company_id', 'id']));

        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sin_point_of_sale_id');
            $table->uuid('issuance_key');
            $table->string('sale_status', 30)->default('CONFIRMED');
            $table->unsignedBigInteger('economic_activity_code');
            $table->unsignedSmallInteger('payment_method_code');
            $table->unsignedSmallInteger('currency_code');
            $table->decimal('subtotal_amount', 18, 5);
            $table->decimal('discount_amount', 18, 5)->default(0);
            $table->decimal('total_amount', 18, 5);
            $table->timestampTz('issued_at');
            $table->timestampTz('inventory_applied_at')->nullable();
            $table->timestampTz('payment_registered_at')->nullable();
            $table->timestampTz('commercial_confirmed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'issuance_key']);
            $table->index(['company_id', 'sale_status', 'issued_at']);
        });

        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('position');
            $table->string('internal_code', 120);
            $table->string('description', 500);
            $table->string('economic_activity_code', 50);
            $table->unsignedBigInteger('siat_product_code');
            $table->unsignedInteger('measurement_unit_code');
            $table->decimal('quantity', 18, 5);
            $table->decimal('unit_price', 18, 5);
            $table->decimal('discount_amount', 18, 5)->default(0);
            $table->decimal('subtotal_amount', 18, 5);
            $table->timestampsTz();

            $table->unique(['company_id', 'sale_id', 'position']);
            $table->index(['company_id', 'product_id']);
        });

        Schema::create('sin_invoice_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('document_sector_code');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestampsTz();

            $table->unique(['company_id', 'document_sector_code']);
        });

        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_id')->nullable()->after('company_id');
            $table->unsignedBigInteger('sin_significant_event_id')->nullable()->after('sin_cufd_id');
            $table->string('pdf_path')->nullable()->after('gzip_path');
            $table->string('pdf_hash', 64)->nullable()->after('pdf_path');

            $table->index(['company_id', 'sin_significant_event_id'], 'sin_invoices_company_event_index');
        });

        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_company_user_foreign FOREIGN KEY (company_id, user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_company_customer_foreign FOREIGN KEY (company_id, customer_id) REFERENCES customers(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_status_check CHECK (sale_status IN ('CONFIRMED','INVOICED','BLOCKED','CANCELLED'))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amounts_check CHECK (subtotal_amount >= 0 AND discount_amount >= 0 AND total_amount >= 0 AND discount_amount <= subtotal_amount AND total_amount = subtotal_amount - discount_amount)');

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_company_sale_foreign FOREIGN KEY (company_id, sale_id) REFERENCES sales(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_company_product_foreign FOREIGN KEY (company_id, product_id) REFERENCES products(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND subtotal_amount >= 0)');

        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_sale_foreign FOREIGN KEY (company_id, sale_id) REFERENCES sales(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_event_direct_foreign FOREIGN KEY (company_id, sin_significant_event_id) REFERENCES sin_significant_events(company_id, id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX sin_invoices_company_sale_unique ON sin_invoice_issues(company_id, sale_id) WHERE sale_id IS NOT NULL');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_pdf_artifact_check CHECK ((pdf_path IS NULL AND pdf_hash IS NULL) OR (pdf_path IS NOT NULL AND pdf_hash IS NOT NULL))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_issued_invoice_identity_changes()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.attempted_invoice_number IS DISTINCT FROM NEW.attempted_invoice_number
                    OR OLD.cuf IS DISTINCT FROM NEW.cuf
                    OR OLD.issued_at IS DISTINCT FROM NEW.issued_at THEN
                    RAISE EXCEPTION 'La identidad fiscal de una factura emitida es inmutable';
                END IF;

                IF OLD.invoice_number IS NOT NULL AND OLD.invoice_number IS DISTINCT FROM NEW.invoice_number THEN
                    RAISE EXCEPTION 'El numero definitivo de factura es inmutable';
                END IF;

                IF OLD.xml_path IS NOT NULL AND (
                    OLD.xml_path IS DISTINCT FROM NEW.xml_path
                    OR OLD.gzip_path IS DISTINCT FROM NEW.gzip_path
                    OR OLD.hash_file IS DISTINCT FROM NEW.hash_file
                ) THEN
                    RAISE EXCEPTION 'Los artefactos XML de una factura emitida son inmutables';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sin_invoice_identity_immutable
            BEFORE UPDATE ON sin_invoice_issues
            FOR EACH ROW EXECUTE FUNCTION prevent_issued_invoice_identity_changes();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sin_invoice_identity_immutable ON sin_invoice_issues');
        DB::statement('DROP FUNCTION IF EXISTS prevent_issued_invoice_identity_changes');
        DB::statement('DROP INDEX IF EXISTS sin_invoices_company_sale_unique');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_pdf_artifact_check');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_event_direct_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_sale_foreign');

        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->dropIndex('sin_invoices_company_event_index');
            $table->dropColumn(['sale_id', 'sin_significant_event_id', 'pdf_path', 'pdf_hash']);
        });

        Schema::dropIfExists('sin_invoice_sequences');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');

        Schema::table('products', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
    }
};
