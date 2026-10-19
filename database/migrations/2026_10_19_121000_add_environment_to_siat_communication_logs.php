<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_communication_logs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('environment_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sin_communication_logs', fn (Blueprint $table) => $table->dropColumn('environment_code'));
    }
};
