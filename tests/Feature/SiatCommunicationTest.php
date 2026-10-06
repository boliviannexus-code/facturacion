<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\User;
use App\Services\Siat\SiatCommunicationResult;
use App\Services\Siat\SiatCommunicationService;
use App\Services\Siat\SiatWsdlRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiatCommunicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_siat_communication_menu_and_page_are_available_to_company_users(): void
    {
        $user = $this->companyUser([
            'dashboard.view',
            'siat-communication.view',
            'siat-communication.verify',
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('SIAT')
            ->assertSee('Verificar comunicacion')
            ->assertSee(route('siat.communication.index'));

        $this
            ->actingAs($user)
            ->get(route('siat.communication.index'))
            ->assertOk()
            ->assertSee('Verificar comunicacion')
            ->assertSee('Falta configurar Token API')
            ->assertSee('Registra el token API y la URL WSDL');
    }

    public function test_company_user_can_verify_siat_communication_with_configured_api_token(): void
    {
        $user = $this->companyUser([
            'siat-communication.view',
            'siat-communication.verify',
        ]);
        $apiToken = SinApiToken::factory()->create([
            'company_id' => $user->company_id,
            'api_token' => 'CURRENT-TOKEN-123456',
            'wsdl_url' => SiatWsdlRegistry::CODES,
        ]);

        $this->mock(SiatCommunicationService::class, function (MockInterface $mock) use ($apiToken): void {
            $mock
                ->shouldReceive('verify')
                ->once()
                ->withArgs(fn (SinApiToken $token): bool => $token->is($apiToken))
                ->andReturn(new SiatCommunicationResult(
                    ok: true,
                    message: 'SIAT respondio correctamente.',
                    operation: 'verificarComunicacion',
                    wsdlUrl: $apiToken->wsdl_url,
                    durationMs: 128,
                    checkedAt: '30/07/2026 14:00:00',
                    response: [
                        'RespuestaComunicacion' => [
                            'transaccion' => true,
                        ],
                    ],
                ));
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.communication.verify'))
            ->assertOk()
            ->assertSee('Comunicacion exitosa')
            ->assertSee('SIAT respondio correctamente.')
            ->assertSee('verificarComunicacion')
            ->assertSee('128 ms');
    }

    public function test_verification_requires_configured_api_token(): void
    {
        $user = $this->companyUser([
            'siat-communication.view',
            'siat-communication.verify',
        ]);

        $this
            ->actingAs($user)
            ->from(route('siat.communication.index'))
            ->post(route('siat.communication.verify'))
            ->assertRedirect(route('siat.communication.index'))
            ->assertSessionHasErrors(['communication']);
    }

    public function test_viewer_can_open_communication_page_but_cannot_execute_verification(): void
    {
        $user = $this->companyUser(['siat-communication.view']);

        $this
            ->actingAs($user)
            ->get(route('siat.communication.index'))
            ->assertOk()
            ->assertDontSee(route('siat.communication.verify'));

        $this
            ->actingAs($user)
            ->post(route('siat.communication.verify'))
            ->assertForbidden();
    }

    public function test_global_administrator_without_company_cannot_access_siat_communication(): void
    {
        Permission::findOrCreate('siat-communication.view');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create(['company_id' => null]);
        $user->assignRole('super_admin');
        $user->givePermissionTo('siat-communication.view');

        $this
            ->actingAs($user)
            ->get(route('siat.communication.index'))
            ->assertForbidden();
    }

    public function test_role_seeder_registers_siat_communication_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin');
        $manager = Role::findByName('manager');
        $viewer = Role::findByName('viewer');
        $cashier = Role::findByName('cashier');

        $this->assertTrue($admin->hasPermissionTo('siat-communication.verify'));
        $this->assertTrue($manager->hasPermissionTo('siat-communication.verify'));
        $this->assertTrue($viewer->hasPermissionTo('siat-communication.view'));
        $this->assertFalse($viewer->hasPermissionTo('siat-communication.verify'));
        $this->assertFalse($cashier->hasPermissionTo('siat-communication.view'));
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
