<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\SiatSoapClientFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiatBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_menu_and_page_are_available_to_company_users(): void
    {
        $user = $this->companyUser([
            'dashboard.view',
            'siat-branches.view',
            'siat-branches.manage',
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('SIAT')
            ->assertSee('Sucursales')
            ->assertSee(route('siat.branches.index'));

        $this
            ->actingAs($user)
            ->get(route('siat.branches.index'))
            ->assertOk()
            ->assertSee('Nueva sucursal')
            ->assertSee('Sucursales registradas');
    }

    public function test_company_user_can_create_main_branch_with_default_point_of_sale(): void
    {
        $user = $this->companyUser([
            'siat-branches.view',
            'siat-branches.manage',
        ]);

        $this
            ->actingAs($user)
            ->post(route('siat.branches.store'), [
                'branch_code' => 0,
                'name' => 'Casa Matriz Central',
                'is_main' => '1',
            ])
            ->assertRedirect(route('siat.branches.index'));

        $branch = SinBranch::query()->firstOrFail();

        $this->assertTrue($branch->is_main);
        $this->assertSame(0, $branch->branch_code);
        $this->assertDatabaseHas('sin_points_of_sale', [
            'company_id' => $user->company_id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 0,
            'name' => 'Punto de venta 0',
            'is_default' => true,
        ]);
    }

    public function test_branch_number_and_main_branch_cannot_be_repeated_per_company(): void
    {
        $user = $this->companyUser([
            'siat-branches.view',
            'siat-branches.manage',
        ]);

        SinBranch::factory()->main()->create(['company_id' => $user->company_id]);
        SinBranch::factory()->create([
            'company_id' => $user->company_id,
            'branch_code' => 2,
        ]);

        $this
            ->actingAs($user)
            ->from(route('siat.branches.index'))
            ->post(route('siat.branches.store'), [
                'branch_code' => 0,
                'name' => 'Otra matriz',
                'is_main' => '1',
            ])
            ->assertRedirect(route('siat.branches.index'))
            ->assertSessionHasErrors(['branch_code', 'is_main']);

        $this
            ->actingAs($user)
            ->from(route('siat.branches.index'))
            ->post(route('siat.branches.store'), [
                'branch_code' => 2,
                'name' => 'Sucursal repetida',
            ])
            ->assertRedirect(route('siat.branches.index'))
            ->assertSessionHasErrors(['branch_code']);
    }

    public function test_company_user_registers_a_point_of_sale_with_the_code_returned_by_siat(): void
    {
        $user = $this->companyUser([
            'siat-branches.view',
            'siat-branches.manage',
        ]);
        $branch = SinBranch::factory()->create([
            'company_id' => $user->company_id,
            'branch_code' => 5,
        ]);
        SinPointOfSale::factory()->default()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $branch->id,
        ]);
        $this->prepareSiatCredentials($user, $branch);

        $soap = new class
        {
            public array $payload = [];

            public function registroPuntoVenta(array $payload): object
            {
                $this->payload = $payload;

                return (object) [
                    'RespuestaRegistroPuntoVenta' => (object) [
                        'codigoPuntoVenta' => 8,
                        'transaccion' => true,
                    ],
                ];
            }
        };
        $factory = \Mockery::mock(SiatSoapClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($soap);
        $this->app->instance(SiatSoapClientFactory::class, $factory);

        $this
            ->actingAs($user)
            ->post(route('siat.branches.points.store', $branch), [
                'point_of_sale_type_code' => 3,
                'name' => 'Caja 8',
                'description' => 'Punto móvil de distribución',
            ])
            ->assertRedirect(route('siat.branches.index'));

        $this->assertDatabaseHas('sin_points_of_sale', [
            'company_id' => $user->company_id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 8,
            'point_of_sale_type_code' => 3,
            'name' => 'Caja 8',
            'description' => 'Punto móvil de distribución',
        ]);

        $this->assertSame(3, $soap->payload['SolicitudRegistroPuntoVenta']['codigoTipoPuntoVenta']);
        $this->assertArrayNotHasKey('codigoPuntoVenta', $soap->payload['SolicitudRegistroPuntoVenta']);
    }

    public function test_company_user_can_consult_and_synchronize_points_from_siat(): void
    {
        $user = $this->companyUser(['siat-branches.view', 'siat-branches.manage']);
        $branch = SinBranch::factory()->create(['company_id' => $user->company_id, 'branch_code' => 4]);
        $this->prepareSiatCredentials($user, $branch);
        SinPointOfSale::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 15,
            'name' => 'Nombre anterior',
        ]);

        $soap = new class
        {
            public function consultaPuntoVenta(array $payload): object
            {
                return (object) [
                    'RespuestaConsultaPuntoVenta' => (object) [
                        'transaccion' => true,
                        'listaPuntosVentas' => [
                            (object) ['codigoPuntoVenta' => 12, 'nombrePuntoVenta' => 'Móvil Norte', 'tipoPuntoVenta' => 'Punto de Venta Móvil'],
                            (object) ['codigoPuntoVenta' => 15, 'nombrePuntoVenta' => 'Cajero Centro', 'tipoPuntoVenta' => 'Punto de Venta Cajero'],
                        ],
                    ],
                ];
            }
        };
        $factory = \Mockery::mock(SiatSoapClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($soap);
        $this->app->instance(SiatSoapClientFactory::class, $factory);

        $this->actingAs($user)
            ->post(route('siat.branches.points.synchronize', $branch))
            ->assertRedirect(route('siat.branches.index'))
            ->assertSessionHas('success', 'Recuperación desde Impuestos completada: 2 recibido(s), 1 nuevo(s) y 1 actualizado(s).');

        $this->assertDatabaseHas('sin_points_of_sale', [
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 12,
            'name' => 'Móvil Norte',
            'point_of_sale_type' => 'Punto de Venta Móvil',
            'point_of_sale_type_code' => 3,
        ]);
        $this->assertDatabaseHas('sin_points_of_sale', [
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 15,
            'name' => 'Cajero Centro',
        ]);
    }

    public function test_viewer_can_open_branches_but_cannot_create(): void
    {
        $user = $this->companyUser(['siat-branches.view']);

        $this
            ->actingAs($user)
            ->get(route('siat.branches.index'))
            ->assertOk()
            ->assertDontSee('Nueva sucursal')
            ->assertDontSee('Guardar sucursal');

        $this
            ->actingAs($user)
            ->post(route('siat.branches.store'), [
                'branch_code' => 1,
                'name' => 'Sucursal',
            ])
            ->assertForbidden();
    }

    public function test_role_seeder_registers_branch_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin');
        $manager = Role::findByName('manager');
        $viewer = Role::findByName('viewer');
        $cashier = Role::findByName('cashier');

        $this->assertTrue($admin->hasPermissionTo('siat-branches.manage'));
        $this->assertTrue($manager->hasPermissionTo('siat-branches.manage'));
        $this->assertTrue($viewer->hasPermissionTo('siat-branches.view'));
        $this->assertFalse($viewer->hasPermissionTo('siat-branches.manage'));
        $this->assertFalse($cashier->hasPermissionTo('siat-branches.view'));
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

    private function prepareSiatCredentials(User $user, SinBranch $branch): void
    {
        $token = SinApiToken::factory()->create(['company_id' => $user->company_id]);
        $authorization = SinAuthorization::factory()->create([
            'company_id' => $user->company_id,
            'branch_code' => $branch->branch_code,
        ]);
        $defaultPoint = SinPointOfSale::query()->firstOrCreate(
            [
                'company_id' => $user->company_id,
                'sin_branch_id' => $branch->id,
                'point_of_sale_code' => 0,
            ],
            ['name' => 'Punto de venta 0', 'is_default' => true, 'is_active' => true],
        );

        SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $defaultPoint->id,
            'branch_code' => $branch->branch_code,
            'point_of_sale_code' => 0,
        ]);
    }
}
