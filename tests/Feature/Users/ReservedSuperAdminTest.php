<?php

namespace Tests\Feature\Users;

use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\User;
use App\Services\UserService;
use App\Support\CompanyContext;
use App\Support\ReservedSuperAdmin;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReservedSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['email' => ReservedSuperAdmin::EMAIL, 'company_id' => Company::factory()->create()->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_reserved_owner_is_global_without_changing_its_historical_company(): void
    {
        $owner = $this->owner();
        $other = Company::factory()->create();
        $company = $owner->company_id;
        self::assertTrue(CompanyContext::isGlobalAdmin($owner));
        self::assertTrue(CompanyContext::hasCompanyScope($owner));
        self::assertNull(CompanyContext::id($owner));
        self::assertSame($company, CompanyContext::activeCompany($owner)->id);
        self::assertTrue(CompanyContext::belongsToUser($other->id, $owner));
        self::assertSame($company, $owner->refresh()->company_id);
    }

    public function test_owner_can_use_company_modules_without_losing_global_administration(): void
    {
        $owner = $this->owner();
        $foreign = Company::factory()->create();
        SinApiToken::factory()->create(['company_id' => $foreign->id]);
        $this->actingAs($owner);
        $this->get(route('sin-api-token.index'))->assertOk()->assertViewHas('apiToken', null)
            ->assertSee(route('cash-registers.index'), false);
        $token = SinApiToken::factory()->create(['company_id' => $owner->company_id]);
        $this->get(route('sin-api-token.index'))->assertOk()
            ->assertViewHas('apiToken', fn ($actual) => $actual->is($token));
        $this->get(route('cash-registers.index'))->assertOk();
        $this->get(route('companies.index'))->assertOk()->assertSee($foreign->name);
        self::assertTrue(CompanyContext::isGlobalAdmin($owner));
        self::assertSame($owner->company_id, CompanyContext::activeCompany($owner)->id);
    }

    public function test_creating_or_promoting_another_super_admin_fails_in_web_api_and_services(): void
    {
        $owner = $this->owner();
        $target = User::factory()->create(['name' => 'Nombre original']);
        $this->actingAs($owner);
        $data = ['name' => 'Otro superusuario', 'email' => 'other@example.test', 'company_id' => $target->company_id,
            'password' => 'test-password-123', 'password_confirmation' => 'test-password-123', 'roles' => ['super_admin']];
        $this->postJson(route('users.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->postJson(route('api.v1.users.store'), $data)->assertUnprocessable()->assertJsonStructure(['data' => ['roles']]);
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->patchJson(route('users.assign-roles', $target), ['roles' => ['super_admin']])->assertUnprocessable();
        $this->patchJson(route('api.v1.users.assign-roles', $target), ['roles' => ['super_admin']])->assertUnprocessable();
        $this->putJson(route('api.v1.users.update', $target), ['name' => 'Cambio parcial', 'email' => $target->email,
            'company_id' => $target->company_id, 'roles' => ['super_admin']])->assertUnprocessable();
        self::assertSame('Nombre original', $target->refresh()->name);
        try {
            app(UserService::class)->create($data);
            self::fail('Debe rechazar la creación directa.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('roles', $e->errors());
        }
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
    }

    public function test_database_rejects_direct_assignment_detachment_identity_changes_and_role_clones(): void
    {
        $owner = $this->owner();
        $other = User::factory()->create();
        $role = Role::findByName('super_admin', 'web');
        foreach ([
            fn () => $other->assignRole('super_admin'),
            fn () => DB::table('model_has_roles')->insert(['model_type' => $owner->getMorphClass(), 'model_id' => $other->id, 'role_id' => $role->id]),
            fn () => $owner->removeRole('super_admin'),
            fn () => DB::table('model_has_roles')->where('role_id', $role->id)->where('model_id', $owner->id)->update(['model_id' => $other->id]),
            fn () => DB::table('users')->where('id', $owner->id)->update(['email' => 'renamed@example.test']),
            fn () => DB::table('users')->where('id', $owner->id)->update(['is_active' => false]),
            fn () => DB::table('users')->where('id', $owner->id)->update(['deleted_at' => now()]),
            fn () => DB::table('users')->where('id', $owner->id)->delete(),
            fn () => User::factory()->create(['email' => 'BOLIVIANNEXUS@GMAIL.COM']),
            fn () => Role::create(['name' => 'super_admin', 'guard_name' => 'api']),
            fn () => Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']),
            fn () => $role->update(['name' => 'renamed_role']),
            fn () => $role->update(['guard_name' => 'api']),
            fn () => $role->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                self::fail('La base de datos debe proteger la cuenta y el rol.');
            } catch (QueryException $e) {
                self::assertContains($e->getCode(), ['23514', '23505']);
            } finally {
                $role->refresh();
            }
        }
        self::assertSame(1, User::role('super_admin')->count());
    }

    public function test_owner_can_edit_its_name_and_sync_roles_without_losing_protection(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $this->get(route('users.edit', $owner))->assertOk()->assertSee('Cuenta de superadministración protegida')->assertSee('readonly', false);
        $this->get(route('users.roles.form', $owner))->assertOk()->assertSee('El rol super_admin de esta cuenta es permanente.');
        $password = $owner->password;
        app(UserService::class)->update($owner, ['name' => 'Nombre actualizado', 'roles' => ['super_admin']]);
        self::assertSame('Nombre actualizado', $owner->refresh()->name);
        self::assertSame($password, $owner->password);
        self::assertTrue($owner->hasRole('super_admin'));
        $owner->syncRoles(['super_admin', 'admin']);
        self::assertTrue($owner->hasAllRoles(['super_admin', 'admin']));
        $owner->syncRoles(['super_admin']);
        self::assertTrue($owner->hasRole('super_admin'));
        self::assertFalse($owner->hasRole('admin'));
        foreach ([
            fn () => app(UserService::class)->update($owner, ['email' => 'changed@example.test']),
            fn () => app(UserService::class)->update($owner, ['is_active' => false]),
            fn () => app(UserService::class)->syncRoles($owner, ['admin']),
            fn () => app(UserService::class)->toggleStatus($owner),
            fn () => app(UserService::class)->delete($owner, $owner),
        ] as $operation) {
            try {
                $operation();
                self::fail('Debe proteger la cuenta.');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
    }

    public function test_company_administrator_cannot_modify_owner_or_see_assignable_super_admin(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->create(['company_id' => $owner->company_id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->get(route('users.create'))->assertOk()->assertDontSee('value="super_admin"', false);
        $this->putJson(route('api.v1.users.update', $owner), ['name' => 'Alterado', 'email' => $owner->email, 'company_id' => $owner->company_id])->assertForbidden();
        $this->patchJson(route('api.v1.users.change-password', $owner), ['password' => 'test-password-999', 'password_confirmation' => 'test-password-999'])->assertForbidden();
        $this->deleteJson(route('api.v1.users.destroy', $owner))->assertForbidden();
        $this->patchJson(route('api.v1.roles.permissions', Role::findByName('super_admin', 'web')), ['permissions' => []])->assertForbidden();
        $this->expectException(ValidationException::class);
        app(UserService::class)->changePassword($owner, 'test-password-888');
    }

    public function test_global_access_does_not_depend_on_historical_company_activity(): void
    {
        $owner = $this->owner();
        $owner->company->update(['is_active' => false]);
        self::assertTrue($owner->refresh()->hasActiveAccess());
        self::assertTrue(CompanyContext::isGlobalAdmin($owner));
    }

    public function test_email_takeover_is_rejected_and_failed_role_updates_are_atomic(): void
    {
        $owner = $this->owner();
        $target = User::factory()->create(['name' => 'Nombre original', 'company_id' => $owner->company_id]);
        $this->actingAs($owner);
        $this->putJson(route('api.v1.users.update', $target), [
            'name' => 'Nuevo', 'email' => 'BOLIVIANNEXUS@GMAIL.COM', 'company_id' => $target->company_id,
        ])->assertUnprocessable()->assertJsonStructure(['data' => ['email']]);
        try {
            app(UserService::class)->update($target, ['name' => 'Cambio parcial', 'roles' => ['missing_role']]);
            self::fail('Debe fallar la asignación de un rol inexistente.');
        } catch (RoleDoesNotExist) {
            self::assertSame('Nombre original', $target->refresh()->name);
        }
    }

    public function test_seeders_preserve_existing_owner_password_company_and_name(): void
    {
        $owner = $this->owner();
        $owner->update(['email' => strtoupper(ReservedSuperAdmin::EMAIL)]);
        $before = $owner->only(['password', 'company_id', 'name']);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        self::assertSame($before, $owner->refresh()->only(['password', 'company_id', 'name']));
        self::assertSame(1, User::role('super_admin')->count());
    }
}
