<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_test_batch_items', function (Blueprint $table): void {
            $table->foreignId('sin_cafc_range_id')->nullable()->after('sin_invoice_package_id')
                ->constrained('sin_cafc_ranges')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_test_batch_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sin_cafc_range_id');
        });
    }
};
