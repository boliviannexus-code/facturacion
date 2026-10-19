<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SinWsdlService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\ConfiguresSiatWsdls;
use Tests\TestCase;

final class SiatWsdlServiceTest extends TestCase
{
    use ConfiguresSiatWsdls;
    use RefreshDatabase;

    public function test_company_user_can_manage_wsdl_catalog_and_defaults_include_new_document_sectors(): void
    {
        $user = $this->companyUser(['dashboard.view', 'sin-api-tokens.view', 'sin-api-tokens.manage']);

        $this->actingAs($user)
            ->get(route('siat.wsdl-services.index'))
            ->assertOk()
            ->assertSee('Servicios WSDL')
            ->assertSee('Factura Tasa Cero')
            ->assertSee('Factura Prevalorada SDCF')
            ->assertSee(route('siat.wsdl-services.index'));

        $this->assertDatabaseHas('sin_wsdl_services', [
            'company_id' => $user->company_id,
            'key' => 'zero_rate_invoice',
            'url' => 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionTasaCero?wsdl',
        ]);

        $this->actingAs($user)
            ->post(route('siat.wsdl-services.store'), [
                'environment_code' => 2,
                'key' => 'nuevo_sector',
                'name' => 'Nuevo documento sector',
                'category' => 'facturacion',
                'url' => 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionNuevo?wsdl',
                'description' => 'Servicio incorporado por normativa.',
                'is_active' => '1',
            ])
            ->assertRedirect(route('siat.wsdl-services.index', ['environment_code' => 2]));

        $service = SinWsdlService::query()->where('key', 'nuevo_sector')->firstOrFail();

        $this->actingAs($user)
            ->put(route('siat.wsdl-services.update', $service), [
                'environment_code' => 2,
                'key' => 'nuevo_sector',
                'name' => 'Documento sector actualizado',
                'category' => 'facturacion',
                'url' => 'https://pilotosiatservicios.impuestos.gob.bo/v3/ServicioFacturacionNuevo?wsdl',
                'description' => 'Enlace actualizado.',
            ])
            ->assertRedirect(route('siat.wsdl-services.index', ['environment_code' => 2]));

        $this->assertDatabaseHas('sin_wsdl_services', [
            'id' => $service->id,
            'name' => 'Documento sector actualizado',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->delete(route('siat.wsdl-services.destroy', $service))
            ->assertRedirect(route('siat.wsdl-services.index', ['environment_code' => 2]));

        $this->assertSoftDeleted('sin_wsdl_services', ['id' => $service->id]);
    }

    public function test_wsdl_form_rejects_external_hosts_and_mismatched_environments(): void
    {
        $user = $this->companyUser(['sin-api-tokens.view', 'sin-api-tokens.manage']);
        foreach (['https://external.test/v2/FacturacionCodigos?wsdl', 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionCodigos?wsdl'] as $url) {
            $this->actingAs($user)->post(route('siat.wsdl-services.store'), [
                'environment_code' => 1, 'key' => 'custom', 'name' => 'Personalizado',
                'category' => 'infraestructura', 'url' => $url, 'is_active' => 1,
            ])->assertSessionHasErrors('url');
        }
    }

    public function test_production_templates_are_visible_but_inactive(): void
    {
        $user = $this->companyUser(['sin-api-tokens.view', 'sin-api-tokens.manage']);
        $this->actingAs($user)->get(route('siat.wsdl-services.index', ['environment_code' => 1]))
            ->assertOk()->assertSee('ServicioFacturacionComputarizada')->assertSee('Inactivo');
        $this->assertDatabaseHas('sin_wsdl_services', ['company_id' => $user->company_id, 'environment_code' => 1, 'key' => 'codes', 'is_active' => false]);
        $this->get(route('sin-api-token.index'))->assertOk()->assertDontSee('name="wsdl_url"', false);
    }

    public function test_same_service_key_can_be_configured_per_environment_and_other_companies_cannot_edit_it(): void
    {
        $user = $this->companyUser(['sin-api-tokens.view', 'sin-api-tokens.manage']);
        foreach ([1 => 'siatrest', 2 => 'pilotosiatservicios'] as $environment => $host) {
            $this->actingAs($user)->post(route('siat.wsdl-services.store'), [
                'environment_code' => $environment, 'key' => 'custom_service', 'name' => 'Servicio propio',
                'category' => 'infraestructura', 'url' => "https://{$host}.impuestos.gob.bo/sfd/ServicioPropio?wsdl", 'is_active' => 1,
            ])->assertSessionHasNoErrors();
        }
        self::assertSame(2, SinWsdlService::where('key', 'custom_service')->count());
        $service = SinWsdlService::where('key', 'custom_service')->firstOrFail();
        $other = $this->companyUser(['sin-api-tokens.view', 'sin-api-tokens.manage']);
        $this->actingAs($other)->delete(route('siat.wsdl-services.destroy', $service))->assertNotFound();
        $this->assertDatabaseHas('sin_wsdl_services', ['id' => $service->id, 'deleted_at' => null]);
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
