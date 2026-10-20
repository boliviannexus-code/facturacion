<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\ReservedSuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
        ]);

        $admin = User::whereRaw('lower(btrim(email)) = ?', [ReservedSuperAdmin::EMAIL])->first() ?? User::firstOrCreate(
            ['email' => ReservedSuperAdmin::EMAIL],
            [
                'name' => 'Administrador',
                'password' => Hash::make('83249842'),
                'is_active' => true,
            ]
        );

        $admin->assignRole('super_admin');
    }
}
