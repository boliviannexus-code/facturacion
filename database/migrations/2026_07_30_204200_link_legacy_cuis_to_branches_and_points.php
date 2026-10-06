<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sin_cuis')
            ->whereNull('sin_point_of_sale_id')
            ->orderBy('id')
            ->chunkById(100, function ($cuisRecords): void {
                foreach ($cuisRecords as $cuis) {
                    $branch = DB::table('sin_branches')
                        ->where('company_id', $cuis->company_id)
                        ->where('branch_code', $cuis->branch_code)
                        ->first(['id']);

                    if (! $branch) {
                        continue;
                    }

                    $pointOfSale = DB::table('sin_points_of_sale')
                        ->where('company_id', $cuis->company_id)
                        ->where('sin_branch_id', $branch->id)
                        ->where('point_of_sale_code', $cuis->point_of_sale_code)
                        ->first(['id']);

                    if (! $pointOfSale) {
                        continue;
                    }

                    DB::table('sin_cuis')
                        ->where('id', $cuis->id)
                        ->update([
                            'sin_branch_id' => $branch->id,
                            'sin_point_of_sale_id' => $pointOfSale->id,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Historical links are intentionally preserved.
    }
};
