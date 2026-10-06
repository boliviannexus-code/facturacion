<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Siat\SiatWsdlRegistry;
use PHPUnit\Framework\TestCase;

final class SiatWsdlRegistryTest extends TestCase
{
    public function test_related_service_preserves_the_configured_environment_host(): void
    {
        self::assertSame(
            'https://siat-production.example/v2/FacturacionOperaciones?wsdl',
            SiatWsdlRegistry::relatedService(
                'https://siat-production.example/v2/FacturacionCodigos?wsdl',
                'FacturacionOperaciones',
            ),
        );

        self::assertSame(
            'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionOperaciones?wsdl',
            SiatWsdlRegistry::relatedService(
                SiatWsdlRegistry::CODES,
                'FacturacionOperaciones',
            ),
        );
    }
}
