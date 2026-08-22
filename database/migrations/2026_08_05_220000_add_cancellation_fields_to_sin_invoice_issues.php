<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->foreignId('cancellation_requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancellation_point_of_sale_id')->nullable()->constrained('sin_points_of_sale')->restrictOnDelete();
            $table->unsignedInteger('cancellation_reason_code')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->unsignedInteger('cancellation_status_code')->nullable();
            $table->jsonb('cancellation_response')->nullable();
            $table->string('cancellation_message')->nullable();
            $table->timestampTz('cancellation_requested_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('cancellation_notified_at')->nullable();
            $table->string('cancellation_notification_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancellation_requested_by_user_id');
            $table->dropConstrainedForeignId('cancellation_point_of_sale_id');
            $table->dropColumn([
                'cancellation_reason_code', 'cancellation_reason', 'cancellation_status_code',
                'cancellation_response', 'cancellation_message', 'cancellation_requested_at',
                'cancelled_at', 'cancellation_notified_at', 'cancellation_notification_error',
            ]);
        });
    }
};
