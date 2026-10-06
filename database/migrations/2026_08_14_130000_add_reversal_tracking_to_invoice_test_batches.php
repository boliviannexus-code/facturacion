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
            $table->string('reversal_status', 32)->nullable();
            $table->unsignedSmallInteger('reversal_requested_count')->default(0);
            $table->unsignedSmallInteger('reversal_processed_count')->default(0);
            $table->unsignedSmallInteger('reversal_successful_count')->default(0);
            $table->unsignedSmallInteger('reversal_failed_count')->default(0);
            $table->timestampTz('reversal_started_at')->nullable();
            $table->timestampTz('reversal_finished_at')->nullable();
        });

        Schema::table('invoice_test_batch_items', function (Blueprint $table): void {
            $table->string('reversal_status', 24)->nullable();
            $table->text('reversal_message')->nullable();
            $table->timestampTz('reversal_started_at')->nullable();
            $table->timestampTz('reversal_finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_test_batch_items', function (Blueprint $table): void {
            $table->dropColumn(['reversal_status', 'reversal_message', 'reversal_started_at', 'reversal_finished_at']);
        });
        Schema::table('invoice_test_batches', function (Blueprint $table): void {
            $table->dropColumn(['reversal_status', 'reversal_requested_count', 'reversal_processed_count',
                'reversal_successful_count', 'reversal_failed_count', 'reversal_started_at', 'reversal_finished_at']);
        });
    }
};
