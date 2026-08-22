<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_test_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sin_point_of_sale_id')->constrained('sin_points_of_sale')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->uuid('batch_key');
            $table->string('batch_status', 32)->default('PENDING');
            $table->unsignedSmallInteger('requested_count');
            $table->unsignedSmallInteger('processed_count')->default(0);
            $table->unsignedSmallInteger('successful_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->unsignedBigInteger('economic_activity_code');
            $table->unsignedSmallInteger('payment_method_code');
            $table->unsignedSmallInteger('currency_code')->default(1);
            $table->decimal('quantity', 18, 5);
            $table->decimal('unit_price', 18, 5);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'batch_key']);
            $table->index(['company_id', 'batch_status', 'created_at']);
        });

        Schema::create('invoice_test_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_test_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sin_invoice_issue_id')->nullable()->constrained('sin_invoice_issues')->restrictOnDelete();
            $table->uuid('issuance_key');
            $table->unsignedSmallInteger('position');
            $table->string('item_status', 24)->default('PENDING');
            $table->text('message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['invoice_test_batch_id', 'position']);
            $table->unique(['company_id', 'issuance_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_test_batch_items');
        Schema::dropIfExists('invoice_test_batches');
    }
};
