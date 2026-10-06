<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedSmallInteger('document_sector_code')->default(1)->after('sale_status');
            $table->index(['company_id', 'document_sector_code', 'issued_at'], 'sales_company_sector_issued_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_company_sector_issued_index');
            $table->dropColumn('document_sector_code');
        });
    }
};
