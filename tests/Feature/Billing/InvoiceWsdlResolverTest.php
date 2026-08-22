<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\SinWsdlService;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\InvoiceWsdlResolver;
use App\Services\Siat\SiatWsdlRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InvoiceWsdlResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_company_zero_rate_wsdl_and_preserves_official_fallback(): void
    {
        $company = Company::factory()->create();
        SinWsdlService::query()->create([
            'company_id' => $company->id,
            'key' => 'zero_rate_invoice',
            'name' => 'Factura Tasa Cero',
            'category' => 'facturacion',
            'url' => 'https://siat.example.test/ServicioFacturacionTasaCero?wsdl',
            'is_active' => true,
        ]);
        $resolver = app(InvoiceWsdlResolver::class);

        self::assertSame(
            'https://siat.example.test/ServicioFacturacionTasaCero?wsdl',
            $resolver->resolve(InvoiceDocumentSector::ZERO_RATE, $company->id),
        );
        self::assertSame(
            SiatWsdlRegistry::PURCHASE_SALE_INVOICE,
            $resolver->resolve(InvoiceDocumentSector::PURCHASE_SALE, $company->id),
        );
    }
}
