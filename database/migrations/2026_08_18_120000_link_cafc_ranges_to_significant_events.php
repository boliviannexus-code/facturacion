<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_cafc_ranges', function (Blueprint $table): void {
            $table->unsignedBigInteger('sin_significant_event_id')->nullable()->after('sin_point_of_sale_id');
            $table->unique(['company_id', 'sin_significant_event_id'], 'sin_cafc_company_event_unique');
        });

        DB::statement('ALTER TABLE sin_cafc_ranges ADD CONSTRAINT sin_cafc_company_event_foreign FOREIGN KEY (company_id, sin_significant_event_id) REFERENCES sin_significant_events(company_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sin_cafc_ranges DROP CONSTRAINT IF EXISTS sin_cafc_company_event_foreign');
        Schema::table('sin_cafc_ranges', function (Blueprint $table): void {
            $table->dropUnique('sin_cafc_company_event_unique');
            $table->dropColumn('sin_significant_event_id');
        });
    }
};
