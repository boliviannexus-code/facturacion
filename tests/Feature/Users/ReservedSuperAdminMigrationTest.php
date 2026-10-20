<?php

namespace Tests\Feature\Users;

use App\Models\Company;
use App\Models\User;
use App\Support\ReservedSuperAdmin;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservedSuperAdminMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_demotes_existing_superusers_and_revokes_access_without_changing_passwords_or_companies(): void
    {
        $migration = require database_path('migrations/2026_10_19_140000_reserve_single_super_administrator.php');
        // Model the legacy installation inside the test transaction.
        $migration->down();
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $owner = User::factory()->create(['email' => ReservedSuperAdmin::EMAIL, 'company_id' => $company->id]);
        $tenantA = User::factory()->create(['company_id' => $company->id]);
        $tenantB = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $unassigned = User::factory()->create(['company_id' => null]);
        $before = [];
        foreach ([$owner, $tenantA, $tenantB, $unassigned] as $user) {
            $user->assignRole('super_admin');
            $before[$user->id] = $user->only(['company_id', 'password']);
            $user->createToken('legacy');
            DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
        $migration->up();

        self::assertSame([$owner->id], User::role('super_admin')->pluck('id')->all());
        foreach ([$owner, $tenantA, $tenantB, $unassigned] as $user) {
            self::assertSame($before[$user->id], $user->refresh()->only(['company_id', 'password']));
        }
        foreach ([$tenantA, $tenantB] as $user) {
            self::assertTrue($user->hasRole('admin'));
            self::assertTrue($user->is_active);
        }
        self::assertFalse($unassigned->is_active);
        foreach ([$tenantA, $tenantB, $unassigned] as $user) {
            $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
            $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
            self::assertNull($user->remember_token);
        }
        $this->assertDatabaseHas('sessions', ['user_id' => $owner->id]);
        self::assertSame(3, DB::table('audits')->where('tags', 'reserved-super-admin-migration')->count());
        // Rolling back constraints must never restore removed privileges.
        $migration->down();
        self::assertSame(1, User::role('super_admin')->count());
        self::assertFalse($unassigned->refresh()->is_active);
    }
}
