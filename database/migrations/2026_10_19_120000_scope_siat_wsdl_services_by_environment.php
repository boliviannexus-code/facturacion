<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_wsdl_services', function (Blueprint $table): void {
            $table->unsignedSmallInteger('environment_code')->nullable();
        });
        DB::statement('DROP INDEX sin_wsdl_services_company_key_unique');
        DB::statement('DROP INDEX sin_wsdl_services_company_url_unique');
        DB::table('sin_wsdl_services')->orderBy('id')->chunkById(200, function ($services): void {
            foreach ($services as $service) {
                $environment = match (strtolower((string) parse_url($service->url, PHP_URL_HOST))) {
                    'pilotosiatservicios.impuestos.gob.bo' => 2,
                    'siatrest.impuestos.gob.bo' => 1,
                    default => null,
                };
                DB::table('sin_wsdl_services')->where('id', $service->id)->update(['environment_code' => $environment]);
            }
        });
        DB::statement('ALTER TABLE sin_wsdl_services ADD CONSTRAINT sin_wsdl_services_environment_check CHECK (environment_code IN (1, 2))');
        DB::statement('CREATE UNIQUE INDEX sin_wsdl_services_company_key_unique ON sin_wsdl_services (company_id, COALESCE(environment_code, 0), key) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX sin_wsdl_services_company_url_unique ON sin_wsdl_services (company_id, COALESCE(environment_code, 0), url) WHERE deleted_at IS NULL');

        $templates = [
            'codes' => ['Servicio de obtención de códigos', 'FacturacionCodigos', 'infraestructura'],
            'operations' => ['Servicio de operaciones', 'FacturacionOperaciones', 'infraestructura'],
            'synchronization' => ['Servicio de sincronización de datos', 'FacturacionSincronizacion', 'infraestructura'],
            'purchase_sale_invoice' => ['Factura compra-venta', 'ServicioFacturacionCompraVenta', 'facturacion'],
            'zero_rate_invoice' => ['Factura Tasa Cero: libros y transporte internacional de carga', 'ServicioFacturacionComputarizada', 'facturacion'],
        ];
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ($templates as $key => [$name, $service, $category]) {
                $url = "https://siatrest.impuestos.gob.bo/v2/{$service}?wsdl";
                $exists = DB::table('sin_wsdl_services')->where('company_id', $companyId)
                    ->where(fn ($query) => $query->where('key', $key)->orWhere('url', $url))
                    ->where(fn ($query) => $query->where('environment_code', 1)->orWhereNull('environment_code'))->exists();
                if (! $exists) {
                    DB::table('sin_wsdl_services')->insert([
                        'company_id' => $companyId, 'environment_code' => 1, 'key' => $key, 'name' => $name,
                        'category' => $category, 'url' => $url,
                        'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Refuse to discard either environment when restoring the old unique indexes.
        if (DB::table('sin_wsdl_services')->whereNull('deleted_at')->select('company_id', 'key')->groupBy('company_id', 'key')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Conserva una sola configuración por empresa y clave antes de revertir esta migración.');
        }
        DB::statement('DROP INDEX sin_wsdl_services_company_key_unique');
        DB::statement('DROP INDEX sin_wsdl_services_company_url_unique');
        DB::statement('ALTER TABLE sin_wsdl_services DROP CONSTRAINT sin_wsdl_services_environment_check');
        Schema::table('sin_wsdl_services', fn (Blueprint $table) => $table->dropColumn('environment_code'));
        DB::statement('CREATE UNIQUE INDEX sin_wsdl_services_company_key_unique ON sin_wsdl_services (company_id, key) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX sin_wsdl_services_company_url_unique ON sin_wsdl_services (company_id, url) WHERE deleted_at IS NULL');
    }
};
