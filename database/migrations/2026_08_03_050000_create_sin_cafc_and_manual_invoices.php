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
        Schema::create('sin_cafc_ranges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_branch_id');
            $table->unsignedBigInteger('sin_point_of_sale_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cafc_code', 128);
            $table->unsignedSmallInteger('document_sector_code');
            $table->unsignedBigInteger('range_start');
            $table->unsignedBigInteger('range_end');
            $table->unsignedBigInteger('next_number');
            $table->string('range_status', 30)->default('AVAILABLE');
            $table->date('authorized_from');
            $table->date('authorized_until');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'cafc_code', 'document_sector_code', 'sin_branch_id', 'sin_point_of_sale_id'], 'sin_cafc_authorization_scope_unique');
            $table->index(['company_id', 'range_status', 'authorized_until']);
            $table->index(['company_id', 'sin_point_of_sale_id', 'document_sector_code'], 'sin_cafc_pos_sector_index');
        });

        Schema::create('sin_manual_contingency_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_invoice_issue_id');
            $table->unsignedBigInteger('sin_cafc_range_id');
            $table->unsignedBigInteger('sin_significant_event_id')->nullable();
            $table->unsignedBigInteger('sin_branch_id');
            $table->unsignedBigInteger('sin_point_of_sale_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transcribed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('manual_invoice_number');
            $table->unsignedSmallInteger('document_sector_code');
            $table->string('manual_status', 40)->default('RESERVED');
            $table->string('original_document_path')->nullable();
            $table->string('original_document_hash', 64)->nullable();
            $table->timestampTz('issued_manually_at')->nullable();
            $table->timestampTz('transcribed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'sin_invoice_issue_id'], 'sin_manual_invoice_issue_unique');
            $table->unique(['company_id', 'sin_cafc_range_id', 'manual_invoice_number'], 'sin_manual_range_number_unique');
            $table->unique(
                ['company_id', 'sin_branch_id', 'sin_point_of_sale_id', 'document_sector_code', 'manual_invoice_number'],
                'sin_manual_fiscal_number_unique'
            );
            $table->index(['company_id', 'manual_status', 'issued_manually_at']);
        });

        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_company_creator_foreign FOREIGN KEY (company_id, created_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_company_updater_foreign FOREIGN KEY (company_id, updated_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_numbers_check CHECK (range_start > 0 AND range_end >= range_start AND next_number >= range_start AND next_number <= range_end + 1)');
        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_dates_check CHECK (authorized_until >= authorized_from)');
        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_status_check CHECK (range_status IN (\'AVAILABLE\',\'EXHAUSTED\',\'SUSPENDED\',\'EXPIRED\'))');

        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_invoice_foreign FOREIGN KEY (company_id, sin_invoice_issue_id) REFERENCES sin_invoice_issues(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_cafc_foreign FOREIGN KEY (company_id, sin_cafc_range_id) REFERENCES sin_cafc_ranges(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_event_foreign FOREIGN KEY (company_id, sin_significant_event_id) REFERENCES sin_significant_events(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_creator_foreign FOREIGN KEY (company_id, created_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_company_transcriber_foreign FOREIGN KEY (company_id, transcribed_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_status_check CHECK (manual_status IN (\'RESERVED\',\'ISSUED\',\'PENDING_TRANSCRIPTION\',\'TRANSCRIBED\',\'PENDING_SEND\',\'VALIDATED\',\'CANCELLED\'))');
        DB::statement('ALTER TABLE sin_manual_contingency_invoices ADD CONSTRAINT sin_manual_artifact_check CHECK ((original_document_path IS NULL AND original_document_hash IS NULL) OR (original_document_path IS NOT NULL AND original_document_hash IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_manual_contingency_invoices');
        Schema::dropIfExists('sin_cafc_ranges');
    }
};
