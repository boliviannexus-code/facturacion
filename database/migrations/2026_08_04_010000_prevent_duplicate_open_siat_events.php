<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sin_events_one_open_per_point_unique
            ON sin_significant_events(company_id, sin_point_of_sale_id)
            WHERE event_status = 'OPEN'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sin_events_one_open_per_point_unique');
    }
};
