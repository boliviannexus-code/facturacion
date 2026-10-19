<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Siat\SiatWsdlRegistry;
use PHPUnit\Framework\TestCase;

final class SiatWsdlRegistryTest extends TestCase
{
    public function test_accepts_only_official_hosts_matching_the_environment_and_preserves_paths(): void
    {
        foreach (['v2', 'sfd'] as $path) {
            self::assertTrue(SiatWsdlRegistry::validUrl("https://siatrest.impuestos.gob.bo/{$path}/FacturacionCodigos?wsdl", 1));
        }
        self::assertFalse(SiatWsdlRegistry::validUrl(SiatWsdlRegistry::CODES, 1));
        self::assertTrue(SiatWsdlRegistry::validUrl(SiatWsdlRegistry::CODES, 2));
        self::assertFalse(SiatWsdlRegistry::validUrl('https://external.test/v2/FacturacionCodigos?wsdl', 1));
        self::assertFalse(SiatWsdlRegistry::validUrl('https://user@siatrest.impuestos.gob.bo/v2/FacturacionCodigos?wsdl', 1));
        self::assertFalse(SiatWsdlRegistry::validUrl('http://siatrest.impuestos.gob.bo/v2/FacturacionCodigos?wsdl', 1));
    }
}
