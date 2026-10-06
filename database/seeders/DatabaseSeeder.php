<?php

namespace Database\Seeders;

use App\Models\User;
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

        $admin = User::updateOrCreate(
            ['email' => 'boliviannexus@gmail.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('83249842'),
                'is_active' => true,
            ]
        );

        $admin->assignRole('super_admin');
    }
}
