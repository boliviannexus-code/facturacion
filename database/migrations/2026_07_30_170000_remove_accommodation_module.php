<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'accommodation-catalogs.manage',
        'spaces.view',
        'spaces.create',
        'spaces.edit',
        'spaces.approve',
    ];

    private const TABLES = [
        'space_review_notes',
        'room_room_service',
        'space_general_service',
        'space_locations',
        'room_photos',
        'space_photos',
        'room_beds',
        'space_rooms',
        'spaces',
        'room_services',
        'general_services',
        'bathroom_types',
        'bed_types',
        'shared_space_types',
        'private_space_types',
        'space_modes',
        'media',
    ];

    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->whereIn('name', self::PERMISSIONS)
                ->delete();
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // El modulo se retiro de forma definitiva y no debe recrearse al revertir.
    }
};
