<?php

namespace App\Services\Siat;

use App\Models\SinWsdlService;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Schema;

class SiatWsdlRegistry
{
    public const SYNCHRONIZATION = 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionSincronizacion?wsdl';

    public const OPERATIONS = 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionOperaciones?wsdl';

    public const CODES = 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionCodigos?wsdl';

    public const PURCHASE_SALE_INVOICE = 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionCompraVenta?wsdl';

    public const ZERO_RATE_INVOICE = 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionTasaCero?wsdl';

    public const PREVALUED_SDCF_INVOICE = 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionPrevalorada?wsdl';

    public static function environmentForUrl(string $url): ?int
    {
        return match (strtolower((string) parse_url($url, PHP_URL_HOST))) {
            'pilotosiatservicios.impuestos.gob.bo' => 2,
            'siatrest.impuestos.gob.bo' => 1,
            default => null,
        };
    }

    public static function validUrl(string $url, int $environment): bool
    {
        $parts = parse_url($url);

        return strlen($url) <= 1000
            && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && self::environmentForUrl($url) === $environment
            && preg_match('#^/(v[0-9]+|sfd)/[A-Za-z0-9]+$#', $parts['path'] ?? '') === 1
            && strtolower($parts['query'] ?? '') === 'wsdl';
    }

    public function ensureDefaults(): void
    {
        $companyId = CompanyContext::id();

        if ($companyId === null || ! Schema::hasTable('sin_wsdl_services')) {
            return;
        }

        self::initializeCompany($companyId);
    }

    public static function initializeCompany(int $companyId): void
    {
        foreach ([2, 1] as $environment) {
            foreach (self::templates($environment) as $service) {
                $exists = SinWsdlService::withoutGlobalScope('company')->withTrashed()
                    ->where('company_id', $companyId)
                    ->where(fn ($query) => $query->where('key', $service['key'])->orWhere('url', $service['url']))
                    ->where(fn ($query) => $query->where('environment_code', $environment)->orWhereNull('environment_code'))
                    ->exists();
                if (! $exists) {
                    SinWsdlService::withoutGlobalScope('company')->create([
                        ...$service, 'company_id' => $companyId,
                        'environment_code' => $environment, 'is_active' => $environment === 2,
                    ]);
                }
            }
        }
    }

    public static function templates(int $environment): array
    {
        $services = array_values(array_filter(self::defaults(), static fn (array $service): bool => $environment === 2 || $service['key'] !== 'prevalued_sdcf_invoice'));

        return array_map(function (array $service) use ($environment): array {
            if ($environment === 1) {
                $service['url'] = str_replace('pilotosiatservicios.impuestos.gob.bo', 'siatrest.impuestos.gob.bo', $service['url']);
                if ($service['key'] === 'zero_rate_invoice') {
                    $service['url'] = 'https://siatrest.impuestos.gob.bo/v2/ServicioFacturacionComputarizada?wsdl';
                }
            }

            return $service;
        }, $services);
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
