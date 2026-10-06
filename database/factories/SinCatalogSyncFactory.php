<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SinCatalogSync;
use App\Services\Siat\SiatWsdlRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinCatalogSync>
 */
class SinCatalogSyncFactory extends Factory
{
    protected $model = SinCatalogSync::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'catalog_key' => 'parametrica_tipo_documento_identidad',
            'catalog_name' => 'Tipos de documento de identidad',
            'operation' => 'sincronizarParametricaTipoDocumentoIdentidad',
            'wsdl_url' => SiatWsdlRegistry::SYNCHRONIZATION,
            'transaccion' => true,
            'items_count' => 2,
            'message' => 'Catalogo sincronizado correctamente.',
            'response' => [
                'RespuestaListaParametricas' => [
                    'transaccion' => true,
                ],
            ],
            'duration_ms' => 140,
            'synced_at' => now(),
        ];
    }
}
