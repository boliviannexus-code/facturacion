<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->foreignId('reversal_requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_point_of_sale_id')->nullable()->constrained('sin_points_of_sale')->restrictOnDelete();
            $table->unsignedInteger('reversal_status_code')->nullable();
            $table->jsonb('reversal_response')->nullable();
            $table->string('reversal_message')->nullable();
            $table->timestampTz('reversal_requested_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampTz('reversal_notified_at')->nullable();
            $table->string('reversal_notification_error')->nullable();
        });
        DB::statement('ALTER TABLE sin_fiscal_status_history DROP CONSTRAINT IF EXISTS sin_history_status_check');
        DB::statement("ALTER TABLE sin_fiscal_status_history ADD CONSTRAINT sin_history_status_check CHECK ((from_status IS NULL OR from_status IN ('NOT_ISSUED','PENDING_ONLINE_SEND','VALIDATED','OBSERVED','REJECTED','UNCERTAIN_SEND','OFFLINE_ISSUED','PENDING_PACKAGE','PACKAGED','PACKAGE_SENT','VALIDATED_AFTER_CONTINGENCY','MANUAL_PENDING_TRANSCRIPTION','MANUAL_TRANSCRIBED','MANUAL_PENDING_SEND','MANUAL_VALIDATED','CANCELLATION_PENDING','CANCELLED_IN_SIAT','REVERSAL_PENDING','REVERSED_IN_SIAT')) AND to_status IN ('NOT_ISSUED','PENDING_ONLINE_SEND','VALIDATED','OBSERVED','REJECTED','UNCERTAIN_SEND','OFFLINE_ISSUED','PENDING_PACKAGE','PACKAGED','PACKAGE_SENT','VALIDATED_AFTER_CONTINGENCY','MANUAL_PENDING_TRANSCRIPTION','MANUAL_TRANSCRIBED','MANUAL_PENDING_SEND','MANUAL_VALIDATED','CANCELLATION_PENDING','CANCELLED_IN_SIAT','REVERSAL_PENDING','REVERSED_IN_SIAT'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sin_fiscal_status_history DROP CONSTRAINT IF EXISTS sin_history_status_check');
        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_requested_by_user_id');
            $table->dropConstrainedForeignId('reversal_point_of_sale_id');
            $table->dropColumn(['reversal_status_code', 'reversal_response', 'reversal_message', 'reversal_requested_at', 'reversed_at', 'reversal_notified_at', 'reversal_notification_error']);
        });
    }
};
