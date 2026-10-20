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
        Schema::create('sin_fiscal_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_invoice_issue_id');
            $table->unsignedBigInteger('sin_siat_attempt_id')->nullable();
            $table->unsignedBigInteger('sin_significant_event_id')->nullable();
            $table->unsignedBigInteger('sin_invoice_package_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->string('emission_mode', 40);
            $table->string('reason_code', 80)->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('changed_at');
            $table->timestampsTz();

            $table->index(['company_id', 'sin_invoice_issue_id', 'changed_at'], 'sin_fiscal_history_invoice_index');
            $table->index(['company_id', 'to_status', 'changed_at'], 'sin_fiscal_history_status_index');
        });

        Schema::create('sin_response_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_siat_attempt_id');
            $table->string('message_key', 64);
            $table->string('service', 80);
            $table->string('message_code', 80)->nullable();
            $table->string('severity', 20)->default('ERROR');
            $table->text('description');
            $table->jsonb('raw_data')->nullable();
            $table->timestampTz('received_at');
            $table->timestampsTz();

            $table->unique(['company_id', 'sin_siat_attempt_id', 'message_key'], 'sin_response_attempt_message_unique');
            $table->index(['company_id', 'message_code', 'received_at']);
            $table->index(['company_id', 'severity', 'received_at']);
        });

        DB::statement('ALTER TABLE sin_fiscal_status_history ADD CONSTRAINT sin_history_company_invoice_foreign FOREIGN KEY (company_id, sin_invoice_issue_id) REFERENCES sin_invoice_issues(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_fiscal_status_history ADD CONSTRAINT sin_history_company_attempt_foreign FOREIGN KEY (company_id, sin_siat_attempt_id) REFERENCES sin_siat_attempts(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_fiscal_status_history ADD CONSTRAINT sin_history_company_event_foreign FOREIGN KEY (company_id, sin_significant_event_id) REFERENCES sin_significant_events(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_fiscal_status_history ADD CONSTRAINT sin_history_company_package_foreign FOREIGN KEY (company_id, sin_invoice_package_id) REFERENCES sin_invoice_packages(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_fiscal_status_history ADD CONSTRAINT sin_history_company_user_foreign FOREIGN KEY (company_id, user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE sin_fiscal_status_history ADD CONSTRAINT sin_history_status_check CHECK ((from_status IS NULL OR from_status IN ('NOT_ISSUED','PENDING_ONLINE_SEND','VALIDATED','OBSERVED','REJECTED','UNCERTAIN_SEND','OFFLINE_ISSUED','PENDING_PACKAGE','PACKAGED','PACKAGE_SENT','VALIDATED_AFTER_CONTINGENCY','MANUAL_PENDING_TRANSCRIPTION','MANUAL_TRANSCRIBED','MANUAL_PENDING_SEND','MANUAL_VALIDATED','CANCELLATION_PENDING','CANCELLED_IN_SIAT')) AND to_status IN ('NOT_ISSUED','PENDING_ONLINE_SEND','VALIDATED','OBSERVED','REJECTED','UNCERTAIN_SEND','OFFLINE_ISSUED','PENDING_PACKAGE','PACKAGED','PACKAGE_SENT','VALIDATED_AFTER_CONTINGENCY','MANUAL_PENDING_TRANSCRIPTION','MANUAL_TRANSCRIBED','MANUAL_PENDING_SEND','MANUAL_VALIDATED','CANCELLATION_PENDING','CANCELLED_IN_SIAT'))");
        DB::statement("ALTER TABLE sin_fiscal_status_history ADD CONSTRAINT sin_history_emission_mode_check CHECK (emission_mode IN ('ONLINE','OFFLINE_DIGITAL','MANUAL_CAFC','PORTAL_WEB','BLOCKED'))");

        DB::statement('ALTER TABLE sin_response_messages ADD CONSTRAINT sin_response_company_attempt_foreign FOREIGN KEY (company_id, sin_siat_attempt_id) REFERENCES sin_siat_attempts(company_id, id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE sin_response_messages ADD CONSTRAINT sin_response_severity_check CHECK (severity IN ('INFO','WARNING','ERROR'))");
        DB::statement('ALTER TABLE sin_response_messages ADD CONSTRAINT sin_response_description_check CHECK (length(trim(description)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_response_messages');
        Schema::dropIfExists('sin_fiscal_status_history');
    }
};
