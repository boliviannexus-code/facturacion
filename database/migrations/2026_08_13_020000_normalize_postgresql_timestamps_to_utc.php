<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->shiftPostgreSqlTimestamps('4 hours');
    }

    public function down(): void
    {
        $this->shiftPostgreSqlTimestamps('-4 hours');
    }

    private function shiftPostgreSqlTimestamps(string $interval): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $connection->statement("SET LOCAL session_replication_role = 'replica'");
        $columns = DB::select(<<<'SQL'
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND data_type = 'timestamp with time zone'
            ORDER BY table_name, ordinal_position
        SQL);

        foreach (collect($columns)->groupBy('table_name') as $tableName => $tableColumns) {
            $table = $grammar->wrap((string) $tableName);
            $assignments = $tableColumns
                ->map(function (object $column) use ($grammar, $interval): string {
                    $name = $grammar->wrap((string) $column->column_name);

                    return "{$name} = {$name} + INTERVAL '{$interval}'";
                })
                ->implode(', ');
            $connection->statement("UPDATE {$table} SET {$assignments}");
        }
    }
};
