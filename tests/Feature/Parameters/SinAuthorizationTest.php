<?php

namespace Tests\Feature\Parameters;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Company;
use App\Models\SinAuthorization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SinAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_menu_and_form_are_available_to_company_users(): void
    {
        $user = $this->companyUser([
            'dashboard.view',
            'sin-authorizations.view',
            'sin-authorizations.manage',
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Parametros')
            ->assertSee(route('parameters.authorization.index'));

        $this
            ->actingAs($user)
            ->get(route('parameters.authorization.index'))
            ->assertOk()
            ->assertSee('Autorizacion SIN')
            ->assertSee('NIT')
            ->assertSee('Razon social')
            ->assertSee('Codigo de sistema')
            ->assertSee('Parametro SIAT: codigoAmbiente')
            ->assertSee('Parametro SIAT: codigoModalidad')
            ->assertSee('Pruebas y Piloto (2)')
            ->assertSee('Computarizada en Linea (2)');

    }

    public function test_company_user_can_enable_forced_offline_emission(): void
    {
        $user = $this->companyUser([
            'sin-authorizations.view',
            'sin-authorizations.manage',
        ]);
        SinAuthorization::factory()->create(['company_id' => $user->company_id]);

        $this->actingAs($user)
            ->put(route('parameters.authorization.update'), [
                'tax_id' => '123456789',
                'legal_name' => 'Empresa Demo SRL',
                'system_code' => '',
                'environment_code' => SiatEnvironment::TestingAndPilot->value,
                'modality_code' => SiatModality::ComputerizedOnline->value,
                'branch_code' => 0,
                'point_of_sale_code' => '',
                'force_offline_emission' => '1',
            ])
            ->assertRedirect(route('parameters.authorization.index'));

        $this->assertTrue(SinAuthorization::query()->firstOrFail()->force_offline_emission);
    }

    public function test_company_user_can_save_audited_authorization_and_system_code_is_encrypted(): void
    {
        config(['audit.console' => true]);

        $user = $this->companyUser([
            'sin-authorizations.view',
            'sin-authorizations.manage',
        ]);

        $this
            ->actingAs($user)
            ->post(route('parameters.authorization.store'), [
                'tax_id' => ' 123456789 ',
                'legal_name' => ' Empresa Demo SRL ',
                'system_code' => ' SIAT-SECRET-001 ',
                'environment_code' => SiatEnvironment::TestingAndPilot->value,
                'modality_code' => SiatModality::ComputerizedOnline->value,
                'branch_code' => '0',
                'point_of_sale_code' => '',
            ])
            ->assertRedirect(route('parameters.authorization.index'));

        $authorization = SinAuthorization::query()->firstOrFail();
        $rawSystemCode = DB::table('sin_authorizations')->value('system_code');
        $audit = DB::table('audits')
            ->where('auditable_type', SinAuthorization::class)
            ->where('auditable_id', $authorization->id)
            ->first();

        $this->assertSame($user->company_id, $authorization->company_id);
        $this->assertSame('123456789', $authorization->tax_id);
        $this->assertSame('Empresa Demo SRL', $authorization->legal_name);
        $this->assertSame('SIAT-SECRET-001', $authorization->system_code);
        $this->assertNotSame('SIAT-SECRET-001', $rawSystemCode);
        $this->assertStringNotContainsString('SIAT-SECRET-001', (string) $audit?->new_values);
        $this->assertDatabaseHas('audits', [
            'company_id' => $user->company_id,
            'event' => 'created',
            'auditable_type' => SinAuthorization::class,
            'auditable_id' => $authorization->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_company_user_can_update_authorization_without_retyping_system_code(): void
    {
        $user = $this->companyUser([
            'sin-authorizations.view',
            'sin-authorizations.manage',
        ]);
        $authorization = SinAuthorization::factory()->create([
            'company_id' => $user->company_id,
            'system_code' => 'CURRENT-CODE-999',
            'environment_code' => SiatEnvironment::TestingAndPilot,
            'modality_code' => SiatModality::ComputerizedOnline,
        ]);

        $this
            ->actingAs($user)
            ->put(route('parameters.authorization.update'), [
                'tax_id' => '987654321',
                'legal_name' => 'Empresa Actualizada SA',
                'system_code' => '',
                'environment_code' => SiatEnvironment::Production->value,
                'modality_code' => SiatModality::ComputerizedOnline->value,
                'branch_code' => 1,
                'point_of_sale_code' => 5,
            ])
            ->assertRedirect(route('parameters.authorization.index'));

        $authorization->refresh();

        $this->assertSame('CURRENT-CODE-999', $authorization->system_code);
        $this->assertSame(SiatEnvironment::Production, $authorization->environment_code);
        $this->assertSame(1, $authorization->branch_code);
        $this->assertSame(5, $authorization->point_of_sale_code);
    }

    public function test_authorization_is_isolated_by_company(): void
    {
        $user = $this->companyUser(['sin-authorizations.view']);
        $foreignCompany = Company::factory()->create();

        SinAuthorization::factory()->create([
            'company_id' => $user->company_id,
            'tax_id' => '111111111',
            'legal_name' => 'Empresa visible',
        ]);
        SinAuthorization::factory()->create([
            'company_id' => $foreignCompany->id,
            'tax_id' => '222222222',
            'legal_name' => 'Empresa oculta',
        ]);

        $this
            ->actingAs($user)
            ->get(route('parameters.authorization.index'))
            ->assertOk()
            ->assertSee('111111111')
            ->assertSee('Empresa visible')
            ->assertDontSee('222222222')
            ->assertDontSee('Empresa oculta');
    }

    public function test_invalid_sin_parameters_are_rejected(): void
    {
        $user = $this->companyUser([
            'sin-authorizations.view',
            'sin-authorizations.manage',
        ]);

        $this
            ->actingAs($user)
            ->from(route('parameters.authorization.index'))
            ->post(route('parameters.authorization.store'), [
                'tax_id' => 'NIT-123',
                'legal_name' => '',
                'system_code' => '',
                'environment_code' => 9,
                'modality_code' => 9,
                'branch_code' => -1,
                'point_of_sale_code' => -1,
            ])
            ->assertRedirect(route('parameters.authorization.index'))
            ->assertSessionHasErrors([
                'tax_id',
                'legal_name',
                'system_code',
                'environment_code',
                'modality_code',
                'branch_code',
                'point_of_sale_code',
            ]);
    }

    public function test_global_administrator_without_company_cannot_access_authorization(): void
    {
        Permission::findOrCreate('sin-authorizations.view');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create(['company_id' => null]);
        $user->assignRole('super_admin');
        $user->givePermissionTo('sin-authorizations.view');

        $this
            ->actingAs($user)
            ->get(route('parameters.authorization.index'))
            ->assertForbidden();
    }

    public function test_role_seeder_registers_authorization_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin');
        $manager = Role::findByName('manager');
        $viewer = Role::findByName('viewer');
        $cashier = Role::findByName('cashier');

        $this->assertTrue($admin->hasPermissionTo('sin-authorizations.manage'));
        $this->assertTrue($manager->hasPermissionTo('sin-authorizations.manage'));
        $this->assertTrue($viewer->hasPermissionTo('sin-authorizations.view'));
        $this->assertFalse($viewer->hasPermissionTo('sin-authorizations.manage'));
        $this->assertFalse($cashier->hasPermissionTo('sin-authorizations.view'));
    }

    public function test_database_constraints_protect_authorization_integrity(): void
    {
        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'sin_authorizations')
            ->where('indexname', 'sin_authorizations_company_id_unique')
            ->count();

        $constraints = DB::table('pg_constraint')
            ->whereIn('conname', [
                'sin_authorizations_tax_id_digits_check',
                'sin_authorizations_required_text_not_blank_check',
                'sin_authorizations_environment_code_check',
                'sin_authorizations_modality_code_check',
                'sin_authorizations_branch_and_pos_non_negative_check',
            ])
            ->count();

        $this->assertSame(1, $indexes);
        $this->assertSame(5, $constraints);

        $company = Company::factory()->create();
        SinAuthorization::factory()->create(['company_id' => $company->id]);

        try {
            DB::transaction(function () use ($company): void {
                SinAuthorization::factory()->create(['company_id' => $company->id]);
            });

            $this->fail('La base de datos permitio duplicar la autorizacion de una empresa.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }
    }

    private function companyUser(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
