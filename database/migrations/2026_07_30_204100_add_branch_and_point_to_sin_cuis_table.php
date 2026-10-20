<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_cuis', function (Blueprint $table): void {
            $table->foreignId('sin_branch_id')->nullable()->after('sin_authorization_id')->constrained('sin_branches')->nullOnDelete();
            $table->foreignId('sin_point_of_sale_id')->nullable()->after('sin_branch_id')->constrained('sin_points_of_sale')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sin_cuis', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sin_point_of_sale_id');
            $table->dropConstrainedForeignId('sin_branch_id');
        });
    }
};
