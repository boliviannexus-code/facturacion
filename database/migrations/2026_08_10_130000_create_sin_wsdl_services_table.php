<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sin_wsdl_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('category', 40)->default('facturacion');
            $table->string('url', 2048);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'is_active']);
        });

        DB::statement('CREATE UNIQUE INDEX sin_wsdl_services_company_key_unique ON sin_wsdl_services (company_id, key) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX sin_wsdl_services_company_url_unique ON sin_wsdl_services (company_id, url) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_wsdl_services');
    }
};
