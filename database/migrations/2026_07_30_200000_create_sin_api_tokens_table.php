<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sin_api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->text('api_token');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->timestampsTz();

            $table->unique('company_id');
            $table->index(['company_id', 'starts_at', 'ends_at']);
        });

        DB::statement(
            'ALTER TABLE sin_api_tokens
            ADD CONSTRAINT sin_api_tokens_required_text_not_blank_check
            CHECK (length(trim(api_token)) > 0)'
        );
        DB::statement(
            'ALTER TABLE sin_api_tokens
            ADD CONSTRAINT sin_api_tokens_validity_range_check
            CHECK (starts_at <= ends_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_api_tokens');
    }
};
