<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_test_batches', function (Blueprint $table): void {
            $table->string('cancellation_status', 32)->nullable();
            $table->unsignedSmallInteger('cancellation_reason_code')->nullable();
            $table->unsignedSmallInteger('cancellation_requested_count')->default(0);
            $table->unsignedSmallInteger('cancellation_processed_count')->default(0);
            $table->unsignedSmallInteger('cancellation_successful_count')->default(0);
            $table->unsignedSmallInteger('cancellation_failed_count')->default(0);
            $table->timestampTz('cancellation_started_at')->nullable();
            $table->timestampTz('cancellation_finished_at')->nullable();
        });

        Schema::table('invoice_test_batch_items', function (Blueprint $table): void {
            $table->string('cancellation_status', 24)->nullable();
            $table->text('cancellation_message')->nullable();
            $table->timestampTz('cancellation_started_at')->nullable();
            $table->timestampTz('cancellation_finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_test_batch_items', function (Blueprint $table): void {
            $table->dropColumn(['cancellation_status', 'cancellation_message', 'cancellation_started_at', 'cancellation_finished_at']);
        });
        Schema::table('invoice_test_batches', function (Blueprint $table): void {
            $table->dropColumn(['cancellation_status', 'cancellation_reason_code', 'cancellation_requested_count',
                'cancellation_processed_count', 'cancellation_successful_count', 'cancellation_failed_count',
                'cancellation_started_at', 'cancellation_finished_at']);
        });
    }
};
