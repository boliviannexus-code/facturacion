<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Siat\InvoiceXmlValidator;
use App\Services\Siat\PurchaseSaleInvoiceXmlBuilder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InvoiceXmlValidatorTest extends TestCase
{
    public function test_accepts_purchase_sale_xml_that_matches_official_xsd(): void
    {
        $xml = (new PurchaseSaleInvoiceXmlBuilder)->build($this->payload());

        app(InvoiceXmlValidator::class)->validatePurchaseSale($xml);

        $this->addToAssertionCount(1);
    }

    public function test_rejects_xml_that_does_not_match_official_xsd(): void
    {
        $xml = (new PurchaseSaleInvoiceXmlBuilder)->build($this->payload());
        $xml = str_replace('<codigoDocumentoSector>1</codigoDocumentoSector>', '<codigoDocumentoSector>2</codigoDocumentoSector>', $xml);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no cumple el XSD oficial');

        app(InvoiceXmlValidator::class)->validatePurchaseSale($xml);
    }

    public function test_accepts_zero_rate_xml_with_sector_eight_contract(): void
    {
        $payload = $this->payload();
        $payload['cabecera']['montoTotalSujetoIva'] = '0';
        $payload['cabecera']['codigoDocumentoSector'] = InvoiceDocumentSector::ZERO_RATE;
        unset($payload['detalle'][0]['numeroSerie'], $payload['detalle'][0]['numeroImei']);

        $xml = (new PurchaseSaleInvoiceXmlBuilder)->build($payload, InvoiceDocumentSector::ZERO_RATE);

        app(InvoiceXmlValidator::class)->validate($xml, InvoiceDocumentSector::ZERO_RATE);

        self::assertStringContainsString('<facturaComputarizadaTasaCero', $xml);
        self::assertStringContainsString('<montoTotalSujetoIva>0</montoTotalSujetoIva>', $xml);
        self::assertStringNotContainsString('numeroSerie', $xml);
        self::assertStringNotContainsString('numeroImei', $xml);
    }

    /** @return array{cabecera: array<string, mixed>, detalle: array<int, array<string, mixed>>} */
    private function payload(): array
    {
        return [
            'cabecera' => [
                'nitEmisor' => '123456789', 'razonSocialEmisor' => 'Empresa Demo SRL',
                'municipio' => 'La Paz', 'telefono' => null, 'numeroFactura' => 1,
                'cuf' => 'ABC123', 'cufd' => 'CUFD123', 'codigoSucursal' => 0,
                'direccion' => 'Av. Siempre Viva', 'codigoPuntoVenta' => 0,
                'fechaEmision' => '2026-07-31T10:30:15.123', 'nombreRazonSocial' => 'Cliente Demo',
                'codigoTipoDocumentoIdentidad' => 5, 'numeroDocumento' => '9876543',
                'complemento' => null, 'codigoCliente' => '9876543', 'codigoMetodoPago' => 1,
                'numeroTarjeta' => null, 'montoTotal' => '10.00', 'montoTotalSujetoIva' => '10.00',
                'codigoMoneda' => 1, 'tipoCambio' => '1.00', 'montoTotalMoneda' => '10.00',
                'montoGiftCard' => null, 'descuentoAdicional' => '0.00', 'codigoExcepcion' => null,
                'cafc' => null, 'leyenda' => 'Leyenda', 'usuario' => 'demo', 'codigoDocumentoSector' => 1,
            ],
            'detalle' => [[
                'actividadEconomica' => '620000', 'codigoProductoSin' => 83141,
                'codigoProducto' => 'PRD-1', 'descripcion' => 'Servicio demo', 'cantidad' => '1',
                'unidadMedida' => 58, 'precioUnitario' => '10.00', 'montoDescuento' => '0.00',
                'subTotal' => '10.00', 'numeroSerie' => null, 'numeroImei' => null,
            ]],
        ];
    }
}
