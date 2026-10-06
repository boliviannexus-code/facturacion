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
        Schema::create('sin_siat_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_invoice_issue_id')->nullable();
            $table->unsignedBigInteger('sin_invoice_package_id')->nullable();
            $table->unsignedBigInteger('sin_significant_event_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->string('operation', 50);
            $table->unsignedInteger('attempt_number');
            $table->string('attempt_status', 30)->default('PENDING');
            $table->string('failure_category', 60)->nullable();
            $table->string('endpoint', 1000)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('reception_code', 128)->nullable();
            $table->unsignedInteger('siat_status_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('message')->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'attempt_status', 'created_at']);
            $table->index(['company_id', 'operation', 'created_at']);
            $table->index(['company_id', 'reception_code']);
        });

        DB::statement('ALTER TABLE sin_siat_attempts ADD CONSTRAINT sin_attempts_company_invoice_foreign FOREIGN KEY (company_id, sin_invoice_issue_id) REFERENCES sin_invoice_issues(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_siat_attempts ADD CONSTRAINT sin_attempts_company_package_foreign FOREIGN KEY (company_id, sin_invoice_package_id) REFERENCES sin_invoice_packages(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_siat_attempts ADD CONSTRAINT sin_attempts_company_event_foreign FOREIGN KEY (company_id, sin_significant_event_id) REFERENCES sin_significant_events(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_siat_attempts ADD CONSTRAINT sin_attempts_company_user_foreign FOREIGN KEY (company_id, user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_siat_attempts ADD CONSTRAINT sin_attempts_single_target_check CHECK (num_nonnulls(sin_invoice_issue_id, sin_invoice_package_id, sin_significant_event_id) = 1)');
        DB::statement('ALTER TABLE sin_siat_attempts ADD CONSTRAINT sin_attempts_status_check CHECK (attempt_status IN (\'PENDING\',\'SENDING\',\'SUCCEEDED\',\'FAILED\',\'UNCERTAIN\'))');
        DB::statement('ALTER TABLE sin_siat_attempts ADD CONSTRAINT sin_attempts_chronology_check CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at >= started_at)');
        DB::statement('CREATE UNIQUE INDEX sin_attempts_invoice_number_unique ON sin_siat_attempts(company_id, sin_invoice_issue_id, attempt_number) WHERE sin_invoice_issue_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX sin_attempts_package_number_unique ON sin_siat_attempts(company_id, sin_invoice_package_id, attempt_number) WHERE sin_invoice_package_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX sin_attempts_event_number_unique ON sin_siat_attempts(company_id, sin_significant_event_id, attempt_number) WHERE sin_significant_event_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_siat_attempts');
    }
};
