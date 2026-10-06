<?php

namespace App\Services\Siat;

use App\Models\SinWsdlService;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SiatWsdlRegistry
{
    public const SYNCHRONIZATION = 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionSincronizacion?wsdl';

    public const OPERATIONS = 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionOperaciones?wsdl';

    public const CODES = 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionCodigos?wsdl';

    public const PURCHASE_SALE_INVOICE = 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionCompraVenta?wsdl';

    public const ZERO_RATE_INVOICE = 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionTasaCero?wsdl';

    public const PREVALUED_SDCF_INVOICE = 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionPrevalorada?wsdl';

    public static function relatedService(string $configuredWsdl, string $service): string
    {
        $parts = parse_url(trim($configuredWsdl));

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('El WSDL configurado para SIAT no es válido.');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/v2/FacturacionCodigos');
        $versionPath = preg_match('#^/v\d+/#', $path) === 1
            ? preg_replace('#^(/v\d+/).*$#', '$1', $path)
            : '/v2/';

        return $parts['scheme'].'://'.$parts['host'].$port.$versionPath.$service.'?wsdl';
    }

    /**
     * @return Collection<int, array{key: string, name: string, url: string}>
     */
    public function all(): Collection
    {
        $companyId = CompanyContext::id();

        if ($companyId !== null && Schema::hasTable('sin_wsdl_services')) {
            $this->ensureDefaults();

            return SinWsdlService::query()
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->map(fn (SinWsdlService $service): array => [
                    'key' => $service->key,
                    'name' => $service->name,
                    'url' => $service->url,
                ]);
        }

        return collect(self::defaults())->map(fn (array $service): array => [
            'key' => $service['key'],
            'name' => $service['name'],
            'url' => $service['url'],
        ]);
    }

    public function ensureDefaults(): void
    {
        $companyId = CompanyContext::id();

        if ($companyId === null || ! Schema::hasTable('sin_wsdl_services')) {
            return;
        }

        $alreadyInitialized = SinWsdlService::query()
            ->withoutGlobalScope('company')
            ->withTrashed()
            ->where('company_id', $companyId)
            ->exists();

        if ($alreadyInitialized) {
            return;
        }

        foreach (self::defaults() as $service) {
            SinWsdlService::query()->create([
                ...$service,
                'company_id' => $companyId,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return array<int, array{key: string, name: string, category: string, url: string, description: string}>
     */
    private static function defaults(): array
    {
        return [
            [
                'key' => 'synchronization',
                'name' => 'Servicio de sincronizacion de datos',
                'category' => 'infraestructura',
                'url' => self::SYNCHRONIZATION,
                'description' => 'Catálogos y parámetros oficiales del SIAT.',
            ],
            [
                'key' => 'operations',
                'name' => 'Servicio de operaciones',
                'category' => 'infraestructura',
                'url' => self::OPERATIONS,
                'description' => 'Puntos de venta, eventos significativos y cierre de operaciones.',
            ],
            [
                'key' => 'codes',
                'name' => 'Servicio de obtencion de codigos',
                'category' => 'infraestructura',
                'url' => self::CODES,
                'description' => 'Obtención de CUIS y CUFD.',
            ],
            [
                'key' => 'purchase_sale_invoice',
                'name' => 'Factura compra-venta',
                'category' => 'facturacion',
                'url' => self::PURCHASE_SALE_INVOICE,
                'description' => 'Servicio de recepción para el documento sector compra-venta.',
            ],
            [
                'key' => 'zero_rate_invoice',
                'name' => 'Factura Tasa Cero: libros y transporte internacional de carga',
                'category' => 'facturacion',
                'url' => self::ZERO_RATE_INVOICE,
                'description' => 'Documento sector 8 para venta de libros y transporte internacional de carga por carretera.',
            ],
            [
                'key' => 'prevalued_sdcf_invoice',
                'name' => 'Factura Prevalorada SDCF',
                'category' => 'facturacion',
                'url' => self::PREVALUED_SDCF_INVOICE,
                'description' => 'Servicio de facturación prevalorada en línea SDCF.',
            ],
        ];
    }
}
