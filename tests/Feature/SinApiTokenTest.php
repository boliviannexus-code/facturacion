<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\User;
use App\Services\Siat\SiatWsdlRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SinApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_token_menu_and_form_are_available_to_company_users(): void
    {
        $user = $this->companyUser([
            'dashboard.view',
            'sin-api-tokens.view',
            'sin-api-tokens.manage',
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('SIAT')
            ->assertSee('Token API')
            ->assertSee(route('sin-api-token.index'));

        $this
            ->actingAs($user)
            ->get(route('sin-api-token.index'))
            ->assertOk()
            ->assertSee('Token API')
            ->assertSee('Token recibido')
            ->assertSee('Servicio WSDL')
            ->assertSee('Servicio de sincronizacion de datos')
            ->assertSee(SiatWsdlRegistry::SYNCHRONIZATION)
            ->assertSee('Servicio de operaciones')
            ->assertSee(SiatWsdlRegistry::OPERATIONS)
            ->assertSee('Servicio de obtencion de codigos')
            ->assertSee(SiatWsdlRegistry::CODES)
            ->assertSee('Factura compra-venta')
            ->assertSee(SiatWsdlRegistry::PURCHASE_SALE_INVOICE)
            ->assertSee('Fecha inicio')
            ->assertSee('Fecha fin');
    }

    public function test_company_user_can_save_audited_api_token_and_secret_is_encrypted(): void
    {
        config(['audit.console' => true]);

        $user = $this->companyUser([
            'sin-api-tokens.view',
            'sin-api-tokens.manage',
        ]);

        $this
            ->actingAs($user)
            ->post(route('sin-api-token.store'), [
                'api_token' => ' TAX-API-TOKEN-SECRET-001 ',
                'wsdl_url' => ' '.SiatWsdlRegistry::CODES.' ',
                'starts_at' => '2026-07-30',
                'ends_at' => '2027-07-30',
            ])
            ->assertRedirect(route('sin-api-token.index'));

        $apiToken = SinApiToken::query()->firstOrFail();
        $rawApiToken = DB::table('sin_api_tokens')->value('api_token');
        $audit = DB::table('audits')
            ->where('auditable_type', SinApiToken::class)
            ->where('auditable_id', $apiToken->id)
            ->first();

        $this->assertSame($user->company_id, $apiToken->company_id);
        $this->assertSame('TAX-API-TOKEN-SECRET-001', $apiToken->api_token);
        $this->assertSame(SiatWsdlRegistry::CODES, $apiToken->wsdl_url);
        $this->assertSame('2026-07-30', $apiToken->starts_at->toDateString());
        $this->assertSame('2027-07-30', $apiToken->ends_at->toDateString());
        $this->assertNotSame('TAX-API-TOKEN-SECRET-001', $rawApiToken);
        $this->assertStringNotContainsString('TAX-API-TOKEN-SECRET-001', (string) $audit?->new_values);
        $this->assertDatabaseHas('audits', [
            'company_id' => $user->company_id,
            'event' => 'created',
            'auditable_type' => SinApiToken::class,
            'auditable_id' => $apiToken->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_company_user_can_update_validity_without_retyping_api_token(): void
    {
        $user = $this->companyUser([
            'sin-api-tokens.view',
            'sin-api-tokens.manage',
        ]);
        $apiToken = SinApiToken::factory()->create([
            'company_id' => $user->company_id,
            'api_token' => 'CURRENT-TOKEN-999',
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);

        $this
            ->actingAs($user)
            ->put(route('sin-api-token.update'), [
                'api_token' => '',
                'wsdl_url' => SiatWsdlRegistry::OPERATIONS,
                'starts_at' => '2026-07-30',
                'ends_at' => '2027-07-30',
            ])
            ->assertRedirect(route('sin-api-token.index'));

        $apiToken->refresh();

        $this->assertSame('CURRENT-TOKEN-999', $apiToken->api_token);
        $this->assertSame(SiatWsdlRegistry::OPERATIONS, $apiToken->wsdl_url);
        $this->assertSame('2026-07-30', $apiToken->starts_at->toDateString());
        $this->assertSame('2027-07-30', $apiToken->ends_at->toDateString());
    }

    public function test_api_token_is_isolated_by_company(): void
    {
        $user = $this->companyUser(['sin-api-tokens.view']);
        $foreignCompany = Company::factory()->create();

        SinApiToken::factory()->create([
            'company_id' => $user->company_id,
            'api_token' => 'VISIBLE-TOKEN-123456',
        ]);
        SinApiToken::factory()->create([
            'company_id' => $foreignCompany->id,
            'api_token' => 'HIDDEN-TOKEN-999999',
        ]);

        $this
            ->actingAs($user)
            ->get(route('sin-api-token.index'))
            ->assertOk()
            ->assertSee('******123456')
            ->assertDontSee('999999');
    }

    public function test_invalid_api_token_data_is_rejected(): void
    {
        $user = $this->companyUser([
            'sin-api-tokens.view',
            'sin-api-tokens.manage',
        ]);

        $this
            ->actingAs($user)
            ->from(route('sin-api-token.index'))
            ->post(route('sin-api-token.store'), [
                'api_token' => '',
                'wsdl_url' => '',
                'starts_at' => '',
                'ends_at' => '2026-01-01',
            ])
            ->assertRedirect(route('sin-api-token.index'))
            ->assertSessionHasErrors([
                'api_token',
                'wsdl_url',
                'starts_at',
            ]);

        $this
            ->actingAs($user)
            ->from(route('sin-api-token.index'))
            ->post(route('sin-api-token.store'), [
                'api_token' => 'TOKEN',
                'wsdl_url' => 'no-es-url',
                'starts_at' => '2026-07-30',
                'ends_at' => '2026-07-29',
            ])
            ->assertRedirect(route('sin-api-token.index'))
            ->assertSessionHasErrors(['wsdl_url', 'ends_at']);

        $this
            ->actingAs($user)
            ->from(route('sin-api-token.index'))
            ->post(route('sin-api-token.store'), [
                'api_token' => 'TOKEN',
                'wsdl_url' => 'https://example.com/manual.wsdl',
                'starts_at' => '2026-07-30',
                'ends_at' => '2026-07-30',
            ])
            ->assertRedirect(route('sin-api-token.index'))
            ->assertSessionHasErrors(['wsdl_url']);
    }

    public function test_global_administrator_without_company_cannot_access_api_token_registration(): void
    {
        Permission::findOrCreate('sin-api-tokens.view');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create(['company_id' => null]);
        $user->assignRole('super_admin');
        $user->givePermissionTo('sin-api-tokens.view');

        $this
            ->actingAs($user)
            ->get(route('sin-api-token.index'))
            ->assertForbidden();
    }

    public function test_role_seeder_registers_api_token_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin');
        $manager = Role::findByName('manager');
        $viewer = Role::findByName('viewer');
        $cashier = Role::findByName('cashier');

        $this->assertTrue($admin->hasPermissionTo('sin-api-tokens.manage'));
        $this->assertTrue($manager->hasPermissionTo('sin-api-tokens.manage'));
        $this->assertTrue($viewer->hasPermissionTo('sin-api-tokens.view'));
        $this->assertFalse($viewer->hasPermissionTo('sin-api-tokens.manage'));
        $this->assertFalse($cashier->hasPermissionTo('sin-api-tokens.view'));
    }

    public function test_database_constraints_protect_api_token_integrity(): void
    {
        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'sin_api_tokens')
            ->where('indexname', 'sin_api_tokens_company_id_unique')
            ->count();

        $constraints = DB::table('pg_constraint')
            ->whereIn('conname', [
                'sin_api_tokens_required_text_not_blank_check',
                'sin_api_tokens_wsdl_url_not_blank_check',
                'sin_api_tokens_validity_range_check',
            ])
            ->count();

        $this->assertSame(1, $indexes);
        $this->assertSame(3, $constraints);

        $company = Company::factory()->create();
        SinApiToken::factory()->create(['company_id' => $company->id]);

        try {
            DB::transaction(function () use ($company): void {
                SinApiToken::factory()->create(['company_id' => $company->id]);
            });

            $this->fail('La base de datos permitio duplicar el token API de una empresa.');
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
