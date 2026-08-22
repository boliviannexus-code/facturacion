<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoice_test_batches', function (Blueprint $table): void {
            $table->unsignedSmallInteger('invoices_per_cycle')->default(1)->after('requested_count');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_test_batches', fn (Blueprint $table) => $table->dropColumn('invoices_per_cycle'));
    }
};
