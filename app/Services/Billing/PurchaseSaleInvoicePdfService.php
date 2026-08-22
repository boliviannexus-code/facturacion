<?php

namespace App\Services\Billing;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoicePrintFormat;
use App\Enums\SiatEnvironment;
use App\Models\SinCatalogItem;
use App\Models\SinInvoiceIssue;
use Illuminate\Support\Carbon;
use TCPDF;

class PurchaseSaleInvoicePdfService
{
    public function render(SinInvoiceIssue $invoice): string
    {
        $invoice->loadMissing(['company', 'customer', 'pointOfSale.branch']);

        $payload = $invoice->payload ?? [];
        $header = $payload['cabecera'] ?? [];
        $details = $payload['detalle'] ?? [];
        $company = $invoice->company;
        $format = InvoicePrintFormat::fromValue($company?->invoice_print_format);

        if ($format === InvoicePrintFormat::Roll) {
            return $this->renderRoll($invoice, $header, $details);
        }

        return $this->renderHalfPage($invoice, $header, $details);
    }

    private function renderHalfPage(SinInvoiceIssue $invoice, array $header, array $details): string
    {
        $pdf = new TCPDF('L', 'mm', 'A5', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $this->drawPilotWatermark($pdf, $invoice);

        $this->drawHeader($pdf, $invoice, $header);
        $this->drawCustomer($pdf, $invoice, $header);
        $this->drawDetails($pdf, $details);
        $this->drawTotals($pdf, $invoice, $header);
        $this->drawFooter($pdf, $invoice, $header);

        return $pdf->Output($this->filename($invoice), 'S');
    }

    private function renderRoll(SinInvoiceIssue $invoice, array $header, array $details): string
    {
        $company = $invoice->company;
        $height = $this->rollHeight($details);
        $pdf = new TCPDF('P', 'mm', [80, $height], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(4, 5, 4);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $this->text($pdf, 4, 6, 72, 6, (string) ($company?->name ?? data_get($header, 'razonSocialEmisor', 'EMPRESA')), 10, 'B', 'C');
        $y = 14;

        $branchLabel = ((int) $invoice->branch_code === 0 || $invoice->pointOfSale?->branch?->is_main) ? 'CASA MATRIZ' : 'SUCURSAL '.$invoice->branch_code;
        $invoiceNumber = $invoice->invoice_number ?? $invoice->attempted_invoice_number;
        $issuedAt = $invoice->issued_at instanceof Carbon
            ? $invoice->issued_at
            : Carbon::parse((string) $invoice->issued_at);

        $this->text($pdf, 4, $y, 72, 4, (string) data_get($header, 'razonSocialEmisor', $company?->legal_name ?? $company?->name), 7, 'B', 'C');
        $y += 5;
        $this->text($pdf, 4, $y, 72, 4, $branchLabel, 6.5, 'B', 'C');
        $y += 4;
        $this->text($pdf, 4, $y, 72, 4, 'PV '.$invoice->point_of_sale_code.' - '.(string) data_get($header, 'municipio', $company?->city ?? 'Bolivia'), 6, '', 'C');
        $y += 4;
        $this->text($pdf, 4, $y, 72, 8, (string) data_get($header, 'direccion', $company?->address ?? 'Sin direccion registrada'), 6, '', 'C');
        $y += 8;
        $this->text($pdf, 4, $y, 72, 4, 'Telefono: '.((string) data_get($header, 'telefono', $company?->phone ?? '-')), 6, '', 'C');
        $y += 7;

        $pdf->Line(4, $y, 76, $y);
        $y += 3;
        $this->text($pdf, 4, $y, 72, 5, $this->fiscalTitle($invoice), 9, 'B', 'C');
        $y += 5;
        $this->text($pdf, 4, $y, 72, 4, InvoiceDocumentSector::fiscalSubtitle((int) $invoice->document_sector_code), 6.5, '', 'C');
        $y += 7;

        $y = $this->rollPair($pdf, $y, 'NIT:', (string) data_get($header, 'nitEmisor', $invoice->tax_id));
        $y = $this->rollPair($pdf, $y, 'Factura Nro:', (string) $invoiceNumber);
        $y = $this->rollPair($pdf, $y, 'Fecha:', $issuedAt->format('d/m/Y H:i:s'));
        $y += 1;
        $this->text($pdf, 4, $y, 72, 4, 'Cod. Autorizacion:', 6, 'B');
        $y += 4;
        $this->text($pdf, 4, $y, 72, 10, (string) data_get($header, 'cuf', $invoice->cuf), 5.2);
        $y += 11;

        $pdf->Line(4, $y, 76, $y);
        $y += 3;
        $y = $this->rollPair($pdf, $y, 'Cliente:', (string) data_get($header, 'nombreRazonSocial', $invoice->customer?->name ?? '-'));
        $y = $this->rollPair($pdf, $y, 'NIT/CI:', (string) data_get($header, 'numeroDocumento', $invoice->customer?->document_number ?? '-'));
        $y = $this->rollPair($pdf, $y, 'Cod. Cliente:', (string) data_get($header, 'codigoCliente', $invoice->customer?->customer_code ?? '-'));
        $y += 2;

        $pdf->Line(4, $y, 76, $y);
        $y += 2;
        $this->text($pdf, 4, $y, 11, 4, 'CANT', 5.5, 'B', 'C');
        $this->text($pdf, 16, $y, 35, 4, 'DESCRIPCION', 5.5, 'B');
        $this->text($pdf, 52, $y, 11, 4, 'P/U', 5.5, 'B', 'R');
        $this->text($pdf, 64, $y, 12, 4, 'SUBT.', 5.5, 'B', 'R');
        $y += 5;
        $pdf->Line(4, $y, 76, $y);
        $y += 2;

        foreach ($details as $detail) {
            $description = (string) data_get($detail, 'descripcion', '-');
            $rowHeight = max(7, min(18, $pdf->getStringHeight(35, $description, false, true, '', 1)));

            $this->text($pdf, 4, $y, 11, $rowHeight, $this->number(data_get($detail, 'cantidad', 0), 2), 5.5, '', 'C');
            $this->text($pdf, 16, $y, 35, $rowHeight, $description, 5.5);
            $this->text($pdf, 52, $y, 11, $rowHeight, $this->number(data_get($detail, 'precioUnitario', 0)), 5.5, '', 'R');
            $this->text($pdf, 64, $y, 12, $rowHeight, $this->number(data_get($detail, 'subTotal', 0)), 5.5, '', 'R');
            $y += $rowHeight + 2;
        }

        $pdf->Line(4, $y, 76, $y);
        $y += 3;
        $y = $this->rollAmount($pdf, $y, 'SUB TOTAL Bs.', $invoice->subtotal_amount);
        $y = $this->rollAmount($pdf, $y, 'DESCUENTO Bs.', $invoice->discount_amount);
        $y = $this->rollAmount($pdf, $y, 'TOTAL Bs.', $invoice->total_amount, true);
        $y = $this->rollAmount($pdf, $y, 'MONTO A PAGAR Bs.', data_get($header, 'montoTotalMoneda', $invoice->total_amount), true);
        $y = $this->rollAmount($pdf, $y, 'IMPORTE BASE CREDITO FISCAL Bs.', $invoice->taxable_amount);
        $y += 2;

        $this->text($pdf, 4, $y, 72, 8, 'Son: '.$this->amountInWords((float) $invoice->total_amount), 6);
        $y += 10;

        $legend = trim((string) data_get($header, 'leyenda', ''));
        if ($legend === '') {
            $legend = $this->randomLegend($invoice);
        }

        $this->text($pdf, 4, $y, 72, 8, 'ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAIS, EL USO ILICITO SERA SANCIONADO PENALMENTE DE ACUERDO A LEY', 5.2, 'B', 'C');
        $y += 10;
        $this->text($pdf, 4, $y, 72, 12, $legend, 5.2, 'B', 'C');
        $y += 14;
        $this->text($pdf, 4, $y, 72, 8, $this->representationGraphicLegend($invoice), 5.2, '', 'C');
        $y += 10;

        $pdf->write2DBarcode($this->qrUrl($invoice, $header, InvoicePrintFormat::Roll), 'QRCODE,M', 24, $y, 32, 32, [
            'border' => 0,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ]);
        $y += 34;
        $this->text($pdf, 4, $y, 72, 8, 'CUF: '.$invoice->cuf, 4.8, '', 'C');

        return $pdf->Output($this->filename($invoice), 'S');
    }

    private function drawHeader(TCPDF $pdf, SinInvoiceIssue $invoice, array $header): void
    {
        $company = $invoice->company;
        $branchLabel = ((int) $invoice->branch_code === 0 || $invoice->pointOfSale?->branch?->is_main) ? 'CASA MATRIZ' : 'SUCURSAL '.$invoice->branch_code;
        $invoiceNumber = $invoice->invoice_number ?? $invoice->attempted_invoice_number;

        $issuerName = (string) data_get($header, 'razonSocialEmisor', $company?->legal_name ?? $company?->name);
        $this->text($pdf, 8, 8, 68, 4, $issuerName, 7.5, 'B', 'C');
        $this->text($pdf, 8, 13, 68, 4, $branchLabel, 6.5, 'B', 'C');
        $this->text($pdf, 8, 17, 68, 4, 'Nro. Punto de Venta '.$invoice->point_of_sale_code, 6, '', 'C');
        $this->text($pdf, 8, 21, 68, 8, (string) data_get($header, 'direccion', $company?->address ?? 'Sin direccion registrada'), 5.8, '', 'C');
        $this->text($pdf, 8, 29, 68, 4, (string) data_get($header, 'municipio', $company?->city ?? 'Bolivia'), 5.8, '', 'C');

        $this->text($pdf, 138, 8, 22, 4, 'NIT', 6.3, 'B');
        $this->text($pdf, 162, 8, 40, 4, (string) data_get($header, 'nitEmisor', $invoice->tax_id), 6.3);
        $this->text($pdf, 138, 13, 22, 4, 'FACTURA N°', 6.3, 'B');
        $this->text($pdf, 162, 13, 40, 4, (string) $invoiceNumber, 6.3);
        $this->text($pdf, 138, 18, 24, 4, 'CÓD. AUTORIZACIÓN', 6.1, 'B');
        $this->text($pdf, 162, 18, 40, 17, (string) data_get($header, 'cuf', $invoice->cuf), 5.5);

        $this->text($pdf, 70, 37, 70, 6, $this->fiscalTitle($invoice), 10.5, 'B', 'C');
        $this->text($pdf, 68, 43, 74, 5, InvoiceDocumentSector::fiscalSubtitle((int) $invoice->document_sector_code), 6.5, '', 'C');
    }

    private function drawCustomer(TCPDF $pdf, SinInvoiceIssue $invoice, array $header): void
    {
        $issuedAt = $invoice->issued_at instanceof Carbon
            ? $invoice->issued_at
            : Carbon::parse((string) $invoice->issued_at);

        $this->text($pdf, 8, 53, 38, 4, 'Fecha:', 6.4, 'B');
        $this->text($pdf, 48, 53, 60, 4, $issuedAt->format('d/m/Y H:i:s'), 6.4);
        $this->text($pdf, 8, 59, 38, 4, 'Nombre/Razón Social:', 6.4, 'B');
        $this->text($pdf, 48, 59, 92, 4, (string) data_get($header, 'nombreRazonSocial', $invoice->customer?->name ?? '-'), 6.4);
        $this->text($pdf, 145, 53, 28, 4, 'NIT/CI/CEX:', 6.4, 'B');
        $this->text($pdf, 177, 53, 25, 4, (string) data_get($header, 'numeroDocumento', $invoice->customer?->document_number ?? '-'), 6.4);
        $this->text($pdf, 145, 59, 28, 4, 'Cód. Cliente:', 6.4, 'B');
        $this->text($pdf, 177, 59, 25, 4, (string) data_get($header, 'codigoCliente', $invoice->customer?->customer_code ?? '-'), 6.4);
    }

    private function drawDetails(TCPDF $pdf, array $details): void
    {
        $x = 8;
        $y = 68;
        $columns = [
            ['CÓDIGO', 'PRODUCTO /', 29, 'C'],
            ['CANTIDAD', '', 21, 'C'],
            ['UNIDAD DE', 'MEDIDA', 23, 'C'],
            ['DESCRIPCIÓN', '', 52, 'C'],
            ['PRECIO', 'UNITARIO', 25, 'C'],
            ['DESCUENTO', '', 22, 'C'],
            ['SUBTOTAL', '', 22, 'C'],
        ];

        $pdf->Rect($x, $y, 194, 38);
        $pdf->SetFont('helvetica', 'B', 5.5);
        $cursor = $x;

        foreach ($columns as [$line1, $line2, $width, $align]) {
            $pdf->MultiCell($width, 4, $line1."\n".$line2, 0, $align, false, 0, $cursor, $y + 2);
            $pdf->Line($cursor + $width, $y, $cursor + $width, $y + 38);
            $cursor += $width;
        }

        $pdf->Line($x, $y + 12, 202, $y + 12);

        $y += 14;
        foreach ($details as $detail) {
            if ($y > 101) {
                break;
            }

            $description = (string) data_get($detail, 'descripcion', '-');
            $rowHeight = max(6, min(10, $pdf->getStringHeight(52, $description, false, true, '', 1)));
            $values = [
                [(string) data_get($detail, 'codigoProducto', '-'), 29, 'L'],
                [$this->number(data_get($detail, 'cantidad', 0), 2), 21, 'R'],
                [$this->unitLabel(data_get($detail, 'unidadMedida')), 23, 'L'],
                [$description, 52, 'L'],
                [$this->number(data_get($detail, 'precioUnitario', 0)), 25, 'R'],
                [$this->number(data_get($detail, 'montoDescuento', 0)), 22, 'R'],
                [$this->number(data_get($detail, 'subTotal', 0)), 22, 'R'],
            ];

            $cursor = $x;
            $pdf->SetFont('helvetica', '', 6);
            foreach ($values as [$value, $width, $align]) {
                $pdf->MultiCell($width, $rowHeight, $value, 0, $align, false, 0, $cursor, $y);
                $cursor += $width;
            }

            $y += $rowHeight;
        }
    }

    private function drawTotals(TCPDF $pdf, SinInvoiceIssue $invoice, array $header): void
    {
        $x = 130;
        $y = 106;
        $rows = [
            ['SUB TOTAL Bs.', $invoice->subtotal_amount],
            ['DESCUENTO Bs.', $invoice->discount_amount],
            ['TOTAL Bs.', $invoice->total_amount],
            ['MONTO GIFT CARD Bs.', data_get($header, 'montoGiftCard', 0)],
            ['MONTO A PAGAR Bs.', data_get($header, 'montoTotalMoneda', $invoice->total_amount)],
            ['IMPORTE BASE CREDITO FISCAL Bs.', $invoice->taxable_amount],
        ];

        foreach ($rows as [$label, $amount]) {
            $pdf->Rect($x, $y, 72, 4.2);
            $this->text($pdf, $x + 1, $y + .2, 48, 3.5, $label, 5.2, 'B', 'R');
            $this->text($pdf, $x + 50, $y + .2, 21, 3.5, $this->number($amount), 5.3, '', 'R');
            $y += 4.2;
        }

        $this->text($pdf, 38, 116, 87, 8, 'Son: '.$this->amountInWords((float) $invoice->total_amount), 5.8, 'B');
    }

    private function drawFooter(TCPDF $pdf, SinInvoiceIssue $invoice, array $header): void
    {
        $legend = trim((string) data_get($header, 'leyenda', ''));

        if ($legend === '') {
            $legend = $this->randomLegend($invoice);
        }
        $qrUrl = $this->qrUrl($invoice, $header, InvoicePrintFormat::HalfPage);

        $this->text($pdf, 38, 132, 164, 4, 'ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY', 4.8, 'B', 'C');
        $this->text($pdf, 38, 137, 164, 4, $legend, 4.8, '', 'C');
        $this->text($pdf, 38, 142, 164, 4, $this->representationGraphicLegend($invoice), 4.5, '', 'C');

        $pdf->write2DBarcode($qrUrl, 'QRCODE,M', 8, 116, 25, 25, [
            'border' => 0,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ]);
    }

    private function drawPilotWatermark(TCPDF $pdf, SinInvoiceIssue $invoice): void
    {
        if ($invoice->environment_code === SiatEnvironment::Production) {
            return;
        }

        $pdf->SetAlpha(0.13);
        $pdf->SetTextColor(80, 80, 80);
        $this->text($pdf, 12, 29, 186, 18, 'SIN VALOR LEGAL', 34, 'B', 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetAlpha(1);
    }

    private function text(TCPDF $pdf, float $x, float $y, float $w, float $h, string $text, float $size = 7, string $style = '', string $align = 'L'): void
    {
        $pdf->SetFont('helvetica', $style, $size);
        $pdf->MultiCell($w, $h, $text, 0, $align, false, 1, $x, $y);
    }

    private function unitLabel(mixed $code): string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return '-';
        }

        $description = SinCatalogItem::query()
            ->where('catalog_key', 'unidades_medida')
            ->where('classifier_code', $code)
            ->value('description');

        return $description ? $code.' - '.$description : $code;
    }

    private function randomLegend(SinInvoiceIssue $invoice): string
    {
        return (string) (SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $invoice->company_id)
            ->where('catalog_key', 'leyendas_factura')
            ->active()
            ->inRandomOrder()
            ->value('description') ?: 'Ley Nro 453: El proveedor debe brindar atencion sin discriminacion.');
    }

    public function representationGraphicLegend(SinInvoiceIssue $invoice): string
    {
        return $invoice->emission_mode === InvoiceEmissionMode::OfflineDigital
            ? '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido fuera de línea, verifique su envío con su proveedor o en la página web www.impuestos.gob.bo”'
            : '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”';
    }

    private function rollPair(TCPDF $pdf, float $y, string $label, string $value): float
    {
        $this->text($pdf, 4, $y, 22, 4, $label, 6, 'B');
        $height = max(4, $pdf->getStringHeight(50, $value, false, true, '', 1));
        $this->text($pdf, 26, $y, 50, $height, $value, 6);

        return $y + $height + 1;
    }

    private function rollAmount(TCPDF $pdf, float $y, string $label, mixed $amount, bool $bold = false): float
    {
        $style = $bold ? 'B' : '';
        $this->text($pdf, 18, $y, 42, 4, $label, 5.8, $style);
        $this->text($pdf, 60, $y, 16, 4, $this->number($amount), 5.8, $style, 'R');

        return $y + 5;
    }

    private function rollHeight(array $details): float
    {
        return max(300, 280 + (count($details) * 20));
    }

    private function qrUrl(SinInvoiceIssue $invoice, array $header, InvoicePrintFormat $format): string
    {
        $baseUrl = $invoice->environment_code === SiatEnvironment::Production
            ? 'https://siat.impuestos.gob.bo/consulta/QR'
            : 'https://pilotosiat.impuestos.gob.bo/consulta/QR';

        return $baseUrl.'?'.http_build_query([
            'nit' => data_get($header, 'nitEmisor', $invoice->tax_id),
            'cuf' => data_get($header, 'cuf', $invoice->cuf),
            'numero' => $invoice->invoice_number ?? $invoice->attempted_invoice_number,
            't' => $format->qrSize(),
        ]);
    }

    private function number(mixed $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }

    private function amountInWords(float $amount): string
    {
        $integer = (int) floor($amount);
        $cents = (int) round(($amount - $integer) * 100);

        return trim($this->words($integer)).' '.str_pad((string) $cents, 2, '0', STR_PAD_LEFT).'/100 BOLIVIANOS';
    }

    private function words(int $number): string
    {
        if ($number === 0) {
            return 'CERO';
        }

        $millions = intdiv($number, 1000000);
        $thousands = intdiv($number % 1000000, 1000);
        $rest = $number % 1000;
        $parts = [];

        if ($millions > 0) {
            $parts[] = $millions === 1 ? 'UN MILLON' : $this->wordsBelowThousand($millions).' MILLONES';
        }

        if ($thousands > 0) {
            $parts[] = $thousands === 1 ? 'MIL' : $this->wordsBelowThousand($thousands).' MIL';
        }

        if ($rest > 0) {
            $parts[] = $this->wordsBelowThousand($rest);
        }

        return implode(' ', $parts);
    }

    private function wordsBelowThousand(int $number): string
    {
        $units = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        $tens = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $hundreds = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

        if ($number === 100) {
            return 'CIEN';
        }

        if ($number < 20) {
            return $units[$number];
        }

        if ($number < 30) {
            return $number === 20 ? 'VEINTE' : 'VEINTI'.$units[$number - 20];
        }

        if ($number < 100) {
            $unit = $number % 10;

            return $tens[intdiv($number, 10)].($unit > 0 ? ' Y '.$units[$unit] : '');
        }

        $rest = $number % 100;

        return $hundreds[intdiv($number, 100)].($rest > 0 ? ' '.$this->wordsBelowThousand($rest) : '');
    }

    private function filename(SinInvoiceIssue $invoice): string
    {
        $number = $invoice->invoice_number ?? $invoice->attempted_invoice_number ?? $invoice->id;

        return 'factura-'.$number.'.pdf';
    }

    private function fiscalTitle(SinInvoiceIssue $invoice): string
    {
        return (int) $invoice->document_sector_code === InvoiceDocumentSector::ZERO_RATE
            ? 'FACTURA TASA CERO'
            : 'FACTURA';
    }
}
