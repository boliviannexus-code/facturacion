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
            $table->string('additional_discount_type', 20)->default('FIXED');
            $table->decimal('additional_discount_percentage', 5, 2)->nullable();
            $table->decimal('exchange_rate', 18, 5)->default(1);
            $table->decimal('gift_card_amount', 18, 5)->default(0);
            $table->decimal('total_amount_currency', 18, 5)->nullable();
            $table->decimal('total_amount_subject_to_vat', 18, 5)->nullable();
        });
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('discount_type', 20)->default('FIXED');
            $table->decimal('discount_percentage', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['discount_type', 'discount_percentage']));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['additional_discount_type', 'additional_discount_percentage', 'exchange_rate', 'gift_card_amount', 'total_amount_currency', 'total_amount_subject_to_vat']));
    }
};
