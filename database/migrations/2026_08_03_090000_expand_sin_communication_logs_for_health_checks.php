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
        Schema::table('sin_communication_logs', function (Blueprint $table): void {
            $table->string('error_type', 60)->nullable()->after('outcome');
            $table->unsignedSmallInteger('attempt_count')->default(1)->after('error_type');
            $table->unsignedInteger('last_request_duration_ms')->default(0)->after('duration_ms');
            $table->boolean('was_retried')->default(false)->after('last_request_duration_ms');
            $table->boolean('contingency_recommended')->default(false)->after('was_retried');
            $table->text('technical_message')->nullable()->after('message');
            $table->text('user_message')->nullable()->after('technical_message');

            $table->index(['company_id', 'error_type', 'checked_at'], 'sin_communication_error_type_index');
            $table->index(['company_id', 'contingency_recommended', 'checked_at'], 'sin_communication_contingency_index');
        });

        DB::statement("ALTER TABLE sin_communication_logs ADD CONSTRAINT sin_communication_error_type_check CHECK (error_type IS NULL OR error_type IN ('AVAILABLE','NO_INTERNET','TIMEOUT','DNS_UNAVAILABLE','SIAT_UNAVAILABLE','INVALID_HTTP_RESPONSE','INVALID_TOKEN','INVALID_CUIS','INVALID_OR_EXPIRED_CUFD','EXPIRED_CERTIFICATE','INVALID_XML','CATALOG_ERROR','AUTHENTICATION_ERROR','LOCAL_CONFIGURATION_ERROR','DATABASE_ERROR','UNKNOWN_ERROR'))");
        DB::statement('ALTER TABLE sin_communication_logs ADD CONSTRAINT sin_communication_attempt_count_check CHECK (attempt_count > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sin_communication_logs DROP CONSTRAINT IF EXISTS sin_communication_attempt_count_check');
        DB::statement('ALTER TABLE sin_communication_logs DROP CONSTRAINT IF EXISTS sin_communication_error_type_check');

        Schema::table('sin_communication_logs', function (Blueprint $table): void {
            $table->dropIndex('sin_communication_error_type_index');
            $table->dropIndex('sin_communication_contingency_index');
            $table->dropColumn([
                'error_type', 'attempt_count', 'last_request_duration_ms', 'was_retried',
                'contingency_recommended', 'technical_message', 'user_message',
            ]);
        });
    }
};
