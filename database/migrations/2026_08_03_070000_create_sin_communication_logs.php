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
        Schema::create('sin_communication_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sin_branch_id')->nullable();
            $table->unsignedBigInteger('sin_point_of_sale_id')->nullable();
            $table->unsignedBigInteger('sin_cuis_id')->nullable();
            $table->unsignedBigInteger('sin_cufd_id')->nullable();
            $table->foreignId('sin_api_token_id')->nullable()->constrained('sin_api_tokens')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 50)->default('VERIFY_COMMUNICATION');
            $table->string('outcome', 40);
            $table->string('failure_category', 60)->nullable();
            $table->string('endpoint', 1000)->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->string('soap_fault_code', 120)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('message')->nullable();
            $table->jsonb('response')->nullable();
            $table->timestampTz('checked_at');
            $table->timestampsTz();

            $table->index(['company_id', 'outcome', 'checked_at']);
            $table->index(['company_id', 'sin_point_of_sale_id', 'checked_at'], 'sin_communication_company_pos_index');
            $table->index(['company_id', 'failure_category', 'checked_at'], 'sin_communication_failure_index');
        });

        DB::statement('ALTER TABLE sin_communication_logs ADD CONSTRAINT sin_communication_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_communication_logs ADD CONSTRAINT sin_communication_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_communication_logs ADD CONSTRAINT sin_communication_company_cuis_foreign FOREIGN KEY (company_id, sin_cuis_id) REFERENCES sin_cuis(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_communication_logs ADD CONSTRAINT sin_communication_company_cufd_foreign FOREIGN KEY (company_id, sin_cufd_id) REFERENCES sin_cufds(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_communication_logs ADD CONSTRAINT sin_communication_company_user_foreign FOREIGN KEY (company_id, user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_communication_logs ADD CONSTRAINT sin_communication_outcome_check CHECK (outcome IN (\'AVAILABLE\',\'UNAVAILABLE\',\'TIMEOUT\',\'INVALID_CONFIGURATION\',\'ERROR\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_communication_logs');
    }
};
