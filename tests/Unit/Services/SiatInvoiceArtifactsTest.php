<?php

namespace Tests\Unit\Services;

use App\Services\Siat\PurchaseSaleInvoiceXmlBuilder;
use App\Services\Siat\SiatCufGenerator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SiatInvoiceArtifactsTest extends TestCase
{
    public function test_cuf_generation_is_deterministic_and_appends_control_code(): void
    {
        $generator = new SiatCufGenerator;

        $cuf = $generator->generate(
            '123456789',
            Carbon::parse('2026-07-31 10:30:15.123'),
            7,
            2,
            1,
            1,
            1,
            25,
            3,
            'CTRL123',
        );

        $this->assertSame($cuf, $generator->generate(
            '123456789',
            Carbon::parse('2026-07-31 10:30:15.123'),
            7,
            2,
            1,
            1,
            1,
            25,
            3,
            'CTRL123',
        ));
        $this->assertStringEndsWith('CTRL123', $cuf);
        $this->assertMatchesRegularExpression('/^[0-9A-F]+CTRL123$/', $cuf);
    }

    public function test_cuf_includes_invoice_document_type_before_document_sector(): void
    {
        $cuf = (new SiatCufGenerator)->generate(
            '8324984019',
            Carbon::parse('2026-07-31 16:00:00.000'),
            0,
            2,
            1,
            1,
            1,
            2,
            1,
            '21913C53170BF74',
        );

        $this->assertSame('2399E576DE29F272059D6E31D590FEF43E9BD878D4C21913C53170BF74', $cuf);
    }

    public function test_cuf_matches_siat_observation_for_invoice_three(): void
    {
        $cuf = (new SiatCufGenerator)->generate(
            '8324984019',
            Carbon::parse('2026-07-31 16:00:00.000'),
            0,
            2,
            1,
            1,
            1,
            3,
            1,
            '21913C53170BF74',
        );

        $this->assertSame('2399E576DE29F272059D6E31D590FEF43E9BD8913F221913C53170BF74', $cuf);
    }

    public function test_cuf_matches_the_official_siat_example(): void
    {
        $cuf = (new SiatCufGenerator)->generate(
            '123456789',
            Carbon::create(2019, 1, 13, 16, 37, 21, 'UTC')->setMillisecond(231),
            0,
            1,
            1,
            1,
            1,
            1,
            0,
            'A19E23EF34124CD',
        );

        $this->assertSame('8727F63A15F8976591FDDE5B387C5D015A29E06A1A19E23EF34124CD', $cuf);
    }

    public function test_purchase_sale_xml_can_be_gzipped_and_hashed(): void
    {
        $xml = (new PurchaseSaleInvoiceXmlBuilder)->build([
            'cabecera' => [
                'nitEmisor' => '123456789',
                'razonSocialEmisor' => 'Empresa Demo SRL',
                'municipio' => 'La Paz',
                'telefono' => null,
                'numeroFactura' => 1,
                'cuf' => 'ABC123',
                'cufd' => 'CUFD123',
                'codigoSucursal' => 0,
                'direccion' => 'Av. Siempre Viva',
                'codigoPuntoVenta' => 0,
                'fechaEmision' => '2026-07-31T10:30:15.123',
                'nombreRazonSocial' => 'Cliente Demo',
                'codigoTipoDocumentoIdentidad' => 5,
                'numeroDocumento' => '9876543',
                'complemento' => null,
                'codigoCliente' => '9876543',
                'codigoMetodoPago' => 1,
                'numeroTarjeta' => null,
                'montoTotal' => '10.00',
                'montoTotalSujetoIva' => '10.00',
                'codigoMoneda' => 1,
                'tipoCambio' => '1.00',
                'montoTotalMoneda' => '10.00',
                'montoGiftCard' => null,
                'descuentoAdicional' => '0.00',
                'codigoExcepcion' => null,
                'cafc' => null,
                'leyenda' => 'Leyenda',
                'usuario' => 'demo',
                'codigoDocumentoSector' => 1,
            ],
            'detalle' => [[
                'actividadEconomica' => '620000',
                'codigoProductoSin' => 83141,
                'codigoProducto' => 'PRD-1',
                'descripcion' => 'Servicio demo',
                'cantidad' => '1',
                'unidadMedida' => 58,
                'precioUnitario' => '10.00',
                'montoDescuento' => '0.00',
                'subTotal' => '10.00',
                'numeroSerie' => null,
                'numeroImei' => null,
            ]],
        ]);
        $gzip = gzencode($xml, 9);

        $this->assertIsString($gzip);
        $this->assertStringContainsString('<facturaComputarizadaCompraVenta', $xml);
        $this->assertStringContainsString('facturaComputarizadaCompraVenta.xsd', $xml);
        $this->assertStringContainsString('<telefono xsi:nil="true"/>', $xml);
        $this->assertSame($xml, gzdecode($gzip));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', hash('sha256', $gzip));
    }
}
