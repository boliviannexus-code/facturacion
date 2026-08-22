<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SinWsdlService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class SiatWsdlServiceTest extends TestCase
{
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
                'key' => 'nuevo_sector',
                'name' => 'Nuevo documento sector',
                'category' => 'facturacion',
                'url' => 'https://siat.example.test/v2/ServicioFacturacionNuevo?wsdl',
                'description' => 'Servicio incorporado por normativa.',
                'is_active' => '1',
            ])
            ->assertRedirect(route('siat.wsdl-services.index'));

        $service = SinWsdlService::query()->where('key', 'nuevo_sector')->firstOrFail();

        $this->actingAs($user)
            ->put(route('siat.wsdl-services.update', $service), [
                'key' => 'nuevo_sector',
                'name' => 'Documento sector actualizado',
                'category' => 'facturacion',
                'url' => 'https://siat.example.test/v3/ServicioFacturacionNuevo?wsdl',
                'description' => 'Enlace actualizado.',
            ])
            ->assertRedirect(route('siat.wsdl-services.index'));

        $this->assertDatabaseHas('sin_wsdl_services', [
            'id' => $service->id,
            'name' => 'Documento sector actualizado',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->delete(route('siat.wsdl-services.destroy', $service))
            ->assertRedirect(route('siat.wsdl-services.index'));

        $this->assertSoftDeleted('sin_wsdl_services', ['id' => $service->id]);
    }

    public function test_active_custom_wsdl_is_available_in_api_token_selector(): void
    {
        $user = $this->companyUser(['sin-api-tokens.view', 'sin-api-tokens.manage']);

        SinWsdlService::query()->create([
            'company_id' => $user->company_id,
            'key' => 'custom_invoice',
            'name' => 'Factura personalizada',
            'category' => 'facturacion',
            'url' => 'https://siat.example.test/v2/ServicioPersonalizado?wsdl',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('sin-api-token.index'))
            ->assertOk()
            ->assertSee('Factura personalizada')
            ->assertSee('https://siat.example.test/v2/ServicioPersonalizado?wsdl');
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
