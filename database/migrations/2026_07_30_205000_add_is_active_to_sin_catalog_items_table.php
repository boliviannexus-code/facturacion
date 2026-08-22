<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_catalog_items', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('description');
            $table->index(['company_id', 'catalog_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('sin_catalog_items', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'catalog_key', 'is_active']);
            $table->dropColumn('is_active');
        });
    }
};
