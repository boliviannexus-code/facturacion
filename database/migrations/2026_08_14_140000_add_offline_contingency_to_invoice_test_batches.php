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
            $table->string('test_mode', 32)->default('ONLINE')->after('batch_status');
            $table->unsignedSmallInteger('event_code')->nullable()->after('document_sector_code');
            $table->string('event_description', 500)->nullable()->after('event_code');
        });

        Schema::table('invoice_test_batch_items', function (Blueprint $table): void {
            $table->foreignId('sin_significant_event_id')->nullable()->after('sin_invoice_issue_id')
                ->constrained('sin_significant_events')->restrictOnDelete();
            $table->foreignId('sin_invoice_package_id')->nullable()->after('sin_significant_event_id')
                ->constrained('sin_invoice_packages')->restrictOnDelete();
            $table->string('stage', 32)->default('PENDING')->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_test_batch_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sin_invoice_package_id');
            $table->dropConstrainedForeignId('sin_significant_event_id');
            $table->dropColumn('stage');
        });

        Schema::table('invoice_test_batches', function (Blueprint $table): void {
            $table->dropColumn(['test_mode', 'event_code', 'event_description']);
        });
    }
};
