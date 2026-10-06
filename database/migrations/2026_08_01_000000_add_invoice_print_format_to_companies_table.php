<?php

use App\Enums\InvoicePrintFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table
                ->string('invoice_print_format', 20)
                ->default(InvoicePrintFormat::HalfPage->value)
                ->after('report_footer');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('invoice_print_format');
        });
    }
};
