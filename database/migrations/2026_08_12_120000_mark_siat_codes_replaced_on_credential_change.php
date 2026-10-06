<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_cuis', function (Blueprint $table): void {
            $table->timestampTz('invalidated_at')->nullable()->after('requested_at');
            $table->string('invalidation_reason', 255)->nullable()->after('invalidated_at');
            $table->index(['company_id', 'invalidated_at']);
        });

        Schema::table('sin_cufds', function (Blueprint $table): void {
            $table->timestampTz('invalidated_at')->nullable()->after('requested_at');
            $table->string('invalidation_reason', 255)->nullable()->after('invalidated_at');
            $table->index(['company_id', 'invalidated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sin_cufds', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'invalidated_at']);
            $table->dropColumn(['invalidation_reason', 'invalidated_at']);
        });

        Schema::table('sin_cuis', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'invalidated_at']);
            $table->dropColumn(['invalidation_reason', 'invalidated_at']);
        });
    }
};
