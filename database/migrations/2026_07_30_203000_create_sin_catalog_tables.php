<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sin_catalog_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sin_api_token_id')->nullable()->constrained('sin_api_tokens')->nullOnDelete();
            $table->foreignId('sin_authorization_id')->nullable()->constrained('sin_authorizations')->nullOnDelete();
            $table->foreignId('sin_cuis_id')->nullable()->constrained('sin_cuis')->nullOnDelete();
            $table->string('catalog_key', 80);
            $table->string('catalog_name');
            $table->string('operation', 120);
            $table->string('wsdl_url', 2048);
            $table->boolean('transaccion')->default(false);
            $table->unsignedInteger('items_count')->default(0);
            $table->text('message')->nullable();
            $table->jsonb('response')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('synced_at');
            $table->timestampsTz();

            $table->index(['company_id', 'catalog_key', 'synced_at']);
            $table->index(['company_id', 'transaccion']);
        });

        Schema::create('sin_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('catalog_key', 80);
            $table->string('item_key', 160);
            $table->string('classifier_code', 120)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('raw_data');
            $table->timestampTz('synced_at');
            $table->timestampsTz();

            $table->unique(['company_id', 'catalog_key', 'item_key']);
            $table->index(['company_id', 'catalog_key']);
            $table->index(['company_id', 'catalog_key', 'classifier_code']);
        });

        DB::statement(
            'ALTER TABLE sin_catalog_syncs
            ADD CONSTRAINT sin_catalog_syncs_required_text_not_blank_check
            CHECK (
                length(trim(catalog_key)) > 0
                AND length(trim(catalog_name)) > 0
                AND length(trim(operation)) > 0
                AND length(trim(wsdl_url)) > 0
            )'
        );
        DB::statement(
            'ALTER TABLE sin_catalog_items
            ADD CONSTRAINT sin_catalog_items_required_text_not_blank_check
            CHECK (
                length(trim(catalog_key)) > 0
                AND length(trim(item_key)) > 0
            )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_catalog_items');
        Schema::dropIfExists('sin_catalog_syncs');
    }
};
