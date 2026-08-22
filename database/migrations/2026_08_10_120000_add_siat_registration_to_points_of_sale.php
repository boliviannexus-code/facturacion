<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_points_of_sale', function (Blueprint $table): void {
            $table->unsignedSmallInteger('point_of_sale_type_code')->nullable()->after('point_of_sale_code');
            $table->string('point_of_sale_type')->nullable()->after('point_of_sale_type_code');
            $table->string('description')->nullable()->after('name');
            $table->timestampTz('registered_at')->nullable()->after('is_active');
            $table->timestampTz('last_synced_at')->nullable()->after('registered_at');
        });
    }

    public function down(): void
    {
        Schema::table('sin_points_of_sale', function (Blueprint $table): void {
            $table->dropColumn([
                'point_of_sale_type_code',
                'point_of_sale_type',
                'description',
                'registered_at',
                'last_synced_at',
            ]);
        });
    }
};
