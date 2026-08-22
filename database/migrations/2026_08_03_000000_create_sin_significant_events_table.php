<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sin_significant_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sin_invoice_issue_id')->nullable()->constrained('sin_invoice_issues')->nullOnDelete();
            $table->foreignId('sin_api_token_id')->nullable()->constrained('sin_api_tokens')->nullOnDelete();
            $table->foreignId('sin_authorization_id')->nullable()->constrained('sin_authorizations')->nullOnDelete();
            $table->foreignId('sin_cuis_id')->nullable()->constrained('sin_cuis')->nullOnDelete();
            $table->foreignId('sin_cufd_id')->nullable()->constrained('sin_cufds')->nullOnDelete();
            $table->unsignedSmallInteger('event_code');
            $table->string('event_description', 500);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at');
            $table->string('reception_code', 128)->nullable();
            $table->boolean('transaccion')->default(false);
            $table->string('status_label', 80)->default('Pendiente');
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('registered_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'registered_at']);
            $table->index(['sin_invoice_issue_id', 'transaccion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_significant_events');
    }
};
