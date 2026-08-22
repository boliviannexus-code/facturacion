<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_significant_events', function (Blueprint $table): void {
            $table->boolean('requires_manual_processing')->default(false)->after('manual_review_required');
        });

        DB::statement(<<<'SQL'
            UPDATE sin_significant_events
            SET requires_manual_processing = true
            WHERE closed_at IS NULL
              AND transaccion = false
        SQL);
    }

    public function down(): void
    {
        Schema::table('sin_significant_events', function (Blueprint $table): void {
            $table->dropColumn('requires_manual_processing');
        });
    }
};
