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
        Schema::create('sin_invoice_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_significant_event_id');
            $table->unsignedBigInteger('sin_branch_id');
            $table->unsignedBigInteger('sin_point_of_sale_id');
            $table->unsignedBigInteger('sin_cuis_id');
            $table->unsignedBigInteger('sin_cufd_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('package_key');
            $table->unsignedBigInteger('package_number');
            $table->string('emission_mode', 40)->default('OFFLINE_DIGITAL');
            $table->string('package_status', 40)->default('CREATED');
            $table->unsignedInteger('invoice_count')->default(0);
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('reception_code', 128)->nullable();
            $table->unsignedInteger('siat_status_code')->nullable();
            $table->text('message')->nullable();
            $table->jsonb('response')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'package_key']);
            $table->unique(['company_id', 'sin_branch_id', 'sin_point_of_sale_id', 'package_number'], 'sin_packages_scope_number_unique');
            $table->index(['company_id', 'package_status', 'created_at']);
            $table->index(['company_id', 'sin_significant_event_id']);
        });

        Schema::create('sin_invoice_package_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_invoice_package_id');
            $table->unsignedBigInteger('sin_invoice_issue_id');
            $table->unsignedInteger('position');
            $table->string('cuf', 256);
            $table->string('file_hash', 64);
            $table->timestampsTz();

            $table->unique(['company_id', 'sin_invoice_package_id', 'sin_invoice_issue_id'], 'sin_package_items_package_invoice_unique');
            $table->unique(['company_id', 'sin_invoice_issue_id'], 'sin_package_items_invoice_unique');
            $table->unique(['company_id', 'sin_invoice_package_id', 'position'], 'sin_package_items_position_unique');
        });

        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_event_foreign FOREIGN KEY (company_id, sin_significant_event_id) REFERENCES sin_significant_events(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_cuis_foreign FOREIGN KEY (company_id, sin_cuis_id) REFERENCES sin_cuis(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_cufd_foreign FOREIGN KEY (company_id, sin_cufd_id) REFERENCES sin_cufds(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_creator_foreign FOREIGN KEY (company_id, created_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_sender_foreign FOREIGN KEY (company_id, sent_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_company_validator_foreign FOREIGN KEY (company_id, validated_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_status_check CHECK (package_status IN (\'CREATED\',\'PENDING_SEND\',\'SENT\',\'PENDING_VALIDATION\',\'VALIDATED\',\'OBSERVED\',\'REJECTED\',\'FAILED\'))');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_emission_mode_check CHECK (emission_mode IN (\'OFFLINE_DIGITAL\',\'MANUAL_CAFC\'))');
        DB::statement('ALTER TABLE sin_invoice_packages ADD CONSTRAINT sin_packages_artifact_check CHECK ((file_path IS NULL AND file_hash IS NULL) OR (file_path IS NOT NULL AND file_hash IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX sin_packages_company_reception_unique ON sin_invoice_packages(company_id, reception_code) WHERE reception_code IS NOT NULL');

        DB::statement('ALTER TABLE sin_invoice_package_items ADD CONSTRAINT sin_package_items_company_package_foreign FOREIGN KEY (company_id, sin_invoice_package_id) REFERENCES sin_invoice_packages(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE sin_invoice_package_items ADD CONSTRAINT sin_package_items_company_invoice_foreign FOREIGN KEY (company_id, sin_invoice_issue_id) REFERENCES sin_invoice_issues(company_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_invoice_package_items');
        Schema::dropIfExists('sin_invoice_packages');
    }
};
