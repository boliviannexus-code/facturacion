<?php

namespace Tests\Feature\Architecture;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccommodationModuleRemovalTest extends TestCase
{
    use RefreshDatabase;

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

    private const PERMISSIONS = [
        'accommodation-catalogs.manage',
        'spaces.view',
        'spaces.create',
        'spaces.edit',
        'spaces.approve',
    ];

    public function test_accommodation_routes_and_tables_are_absent(): void
    {
        $routeNames = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter();

        $this->assertFalse($routeNames->contains(
            fn (string $name): bool => str_starts_with($name, 'spaces.')
                || str_starts_with($name, 'admin.spaces.')
                || str_starts_with($name, 'admin.accommodation-catalogs.')
                || $name === 'datatables.spaces'
        ));

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "La tabla {$table} no debe existir.");
        }
    }

    public function test_permission_seeder_does_not_recreate_accommodation_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (self::PERMISSIONS as $permission) {
            $this->assertDatabaseMissing('permissions', ['name' => $permission]);
        }
    }
}
