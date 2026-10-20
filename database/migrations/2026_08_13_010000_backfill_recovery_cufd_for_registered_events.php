<?php

use App\Enums\SignificantEventStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sin_significant_events')
            ->where('event_status', SignificantEventStatus::Registered->value)
            ->whereNull('recovery_sin_cufd_id')
            ->whereNotNull('sin_cufd_id')
            ->update([
                'recovery_sin_cufd_id' => DB::raw('sin_cufd_id'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The original CUFD is retained; this backfill is intentionally not reverted.
    }
};
