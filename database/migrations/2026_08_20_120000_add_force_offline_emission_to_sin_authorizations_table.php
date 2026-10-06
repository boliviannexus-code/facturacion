<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_authorizations', function (Blueprint $table): void {
            $table->boolean('force_offline_emission')
                ->default(false)
                ->after('point_of_sale_code');
        });
    }

    public function down(): void
    {
        Schema::table('sin_authorizations', function (Blueprint $table): void {
            $table->dropColumn('force_offline_emission');
        });
    }
};
