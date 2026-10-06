<?php

namespace Tests\Unit\Services;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoicePrintFormat;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SinBranch;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Services\Billing\PurchaseSaleInvoicePdfService;
use Tests\TestCase;

class PurchaseSaleInvoicePdfServiceTest extends TestCase
{
    public function test_renders_purchase_sale_invoice_pdf(): void
    {
        $invoice = $this->invoice();

        $pdf = app(PurchaseSaleInvoicePdfService::class)->render($invoice);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('/MediaBox [0.000000 0.000000 595.276000 419.528000]', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_renders_purchase_sale_invoice_as_roll_when_company_prefers_roll(): void
    {
        $invoice = $this->invoice(InvoicePrintFormat::Roll);

        $pdf = app(PurchaseSaleInvoicePdfService::class)->render($invoice);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('/MediaBox [0.000000 0.000000 226.', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_half_page_watermark_is_only_drawn_in_pilot_environment(): void
    {
        $pilotPdf = app(PurchaseSaleInvoicePdfService::class)->render($this->invoice(environmentCode: 2));
        $productionPdf = app(PurchaseSaleInvoicePdfService::class)->render($this->invoice(environmentCode: 1));

        $this->assertStringContainsString('/ca 0.130000', $pilotPdf);
        $this->assertStringNotContainsString('/ca 0.130000', $productionPdf);
    }

    public function test_representation_graphic_legend_matches_the_invoice_emission_mode(): void
    {
        $service = app(PurchaseSaleInvoicePdfService::class);
        $online = $this->invoice();
        $offline = $this->invoice();
        $offline->emission_mode = InvoiceEmissionMode::OfflineDigital;

        $this->assertStringContainsString('emitido en una modalidad de facturación en línea', $service->representationGraphicLegend($online));
        $this->assertStringContainsString('emitido fuera de línea, verifique su envío', $service->representationGraphicLegend($offline));
    }

    public function test_verification_url_uses_the_correct_siat_environment_and_invoice_data(): void
    {
        $service = app(PurchaseSaleInvoicePdfService::class);

        $this->assertSame(
            'https://pilotosiat.impuestos.gob.bo/consulta/QR?nit=123456789&cuf=ABC123&numero=1&t=2',
            $service->verificationUrl($this->invoice(environmentCode: 2)),
        );
        $this->assertSame(
            'https://siat.impuestos.gob.bo/consulta/QR?nit=123456789&cuf=ABC123&numero=1&t=2',
            $service->verificationUrl($this->invoice(environmentCode: 1)),
        );
    }

    public function test_zero_rate_invoice_uses_the_complete_official_sector_title(): void
    {
        $invoice = $this->invoice();
        $invoice->company_id = PHP_INT_MAX;
        $invoice->document_sector_code = 8;

        $this->assertSame(
            'FACTURA DE TASA CERO POR VENTA DE LIBROS Y TRANSPORTE INTERNACIONAL DE CARGA',
            app(PurchaseSaleInvoicePdfService::class)->fiscalTitle($invoice),
        );
    }

    public function test_long_issuer_name_renders_without_breaking_the_pdf(): void
    {
        $invoice = $this->invoice();
        $payload = $invoice->payload;
        $payload['cabecera']['razonSocialEmisor'] = 'EMPRESA DE SERVICIOS COMERCIALES Y TRANSPORTE INTERNACIONAL COPACABANA RESPONSABILIDAD LIMITADA';
        $invoice->payload = $payload;

        $pdf = app(PurchaseSaleInvoicePdfService::class)->render($invoice);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_half_page_paginates_all_500_items_and_numbers_every_sheet(): void
    {
        $invoice = $this->invoice(environmentCode: 1);
        $payload = $invoice->payload;
        $template = $payload['detalle'][0];
        $payload['detalle'] = [];
        for ($i = 1; $i <= 500; $i++) {
            $payload['detalle'][] = array_replace($template, [
                'codigoProducto' => sprintf('ITEM%04d', $i),
                'descripcion' => 'Producto de prueba',
            ]);
        }
        $invoice->payload = $payload;
        $pdf = app(PurchaseSaleInvoicePdfService::class)->render($invoice);
        $streams = $this->pageStreams($pdf);
        $this->assertGreaterThan(1, count($streams));
        foreach ($streams as $index => $stream) {
            $this->assertStringContainsString('('.($index + 1).' de '.count($streams).')', $stream);
            $this->assertStringContainsString('(123456789)', $stream);
        }
        $content = implode("\n", $streams);
        for ($i = 1; $i <= 500; $i++) {
            $this->assertSame(1, substr_count($content, '('.sprintf('ITEM%04d', $i).')'));
        }
        $this->assertSame(1, substr_count($content, '(TOTAL Bs.)'));
        $this->assertStringContainsString('(TOTAL Bs.)', $streams[array_key_last($streams)]);
    }

    public function test_long_description_continues_without_losing_its_end_or_next_item(): void
    {
        $invoice = $this->invoice();
        $payload = $invoice->payload;
        $template = $payload['detalle'][0];
        $payload['detalle'] = [
            array_replace($template, ['codigoProducto' => 'LONG001', 'descripcion' => str_repeat('Descripcion extensa ', 24).'FINDETALLE']),
            array_replace($template, ['codigoProducto' => 'NEXT002']),
        ];
        $invoice->payload = $payload;
        $streams = $this->pageStreams(app(PurchaseSaleInvoicePdfService::class)->render($invoice));
        $this->assertGreaterThan(1, count($streams));
        $content = implode("\n", $streams);
        $this->assertSame(1, substr_count($content, '(LONG001)'));
        $this->assertSame(1, substr_count($content, '(NEXT002)'));
        $this->assertStringContainsString('FINDETALLE', $content);
        $this->assertSame(24, substr_count($content, 'Descripcion'));
    }

    public function test_single_sheet_has_numbering_without_an_extra_blank_page(): void
    {
        $streams = $this->pageStreams(app(PurchaseSaleInvoicePdfService::class)->render($this->invoice()));
        $this->assertCount(1, $streams);
        $this->assertStringContainsString('(1 de 1)', $streams[0]);
    }

    private function pageStreams(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        $streams = [];
        foreach ($matches[1] as $encoded) {
            $decoded = @gzuncompress($encoded);
            if ($decoded !== false && str_contains($decoded, ' de ')) {
                $streams[] = $decoded;
            }
        }

        return $streams;
    }

    private function invoice(?InvoicePrintFormat $printFormat = null, int $environmentCode = 2): SinInvoiceIssue
    {
        $company = new Company([
            'name' => 'Empresa Demo',
            'legal_name' => 'Empresa Demo SRL',
            'tax_id' => '123456789',
            'phone' => '2222222',
            'address' => 'Av. Siempre Viva',
            'city' => 'La Paz',
            'invoice_print_format' => ($printFormat ?? InvoicePrintFormat::HalfPage)->value,
        ]);
        $customer = new Customer([
            'name' => 'Cliente Demo',
            'document_number' => '61002266',
            'customer_code' => 'CLI-61002266',
        ]);
        $branch = new SinBranch([
            'branch_code' => 0,
            'name' => 'Casa Matriz',
            'is_main' => true,
        ]);
        $pointOfSale = new SinPointOfSale([
            'point_of_sale_code' => 2,
            'name' => 'PV 2',
        ]);
        $pointOfSale->setRelation('branch', $branch);

        $invoice = new SinInvoiceIssue([
            'company_id' => 1,
            'tax_id' => '123456789',
            'environment_code' => $environmentCode,
            'modality_code' => 2,
            'emission_mode' => InvoiceEmissionMode::Online,
            'branch_code' => 0,
            'point_of_sale_code' => 2,
            'attempted_invoice_number' => 1,
            'invoice_number' => 1,
            'cuf' => 'ABC123',
            'cufd_code' => 'CUFD123',
            'subtotal_amount' => 9.70,
            'discount_amount' => 0,
            'total_amount' => 9.70,
            'taxable_amount' => 9.70,
            'issued_at' => now(),
            'payload' => [
                'cabecera' => [
                    'razonSocialEmisor' => 'Empresa Demo SRL',
                    'nitEmisor' => '123456789',
                    'numeroFactura' => 1,
                    'cuf' => 'ABC123',
                    'direccion' => 'Av. Siempre Viva',
                    'municipio' => 'La Paz',
                    'telefono' => '2222222',
                    'nombreRazonSocial' => 'Cliente Demo',
                    'numeroDocumento' => '61002266',
                    'codigoCliente' => 'CLI-61002266',
                    'leyenda' => 'Ley Nro 453: Los servicios deben suministrarse en condiciones de inocuidad, calidad y seguridad.',
                ],
                'detalle' => [[
                    'codigoProducto' => '100150',
                    'cantidad' => '1.00',
                    'unidadMedida' => '',
                    'descripcion' => 'Servicio demo',
                    'precioUnitario' => '9.70',
                    'montoDescuento' => '0.00',
                    'subTotal' => '9.70',
                ]],
            ],
        ]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('pointOfSale', $pointOfSale);

        return $invoice;
    }
}
