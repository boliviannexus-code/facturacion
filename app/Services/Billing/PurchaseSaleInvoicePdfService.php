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
    private const FONT_SIZE_INCREASE = 1.0;

    public function render(SinInvoiceIssue $invoice, ?InvoicePrintFormat $requestedFormat = null): string
    {
        $invoice->loadMissing(['company', 'customer', 'pointOfSale.branch']);

        $payload = $invoice->payload ?? [];
        $header = $payload['cabecera'] ?? [];
        $details = $payload['detalle'] ?? [];
        $company = $invoice->company;
        $format = $requestedFormat ?? InvoicePrintFormat::fromValue($company?->invoice_print_format);

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

        $headerBottom = $this->drawHeader($pdf, $invoice, $header);
        $customerBottom = $this->drawCustomer($pdf, $invoice, $header, $headerBottom);
        $tableY = max(68, $customerBottom + 1);
        $pages = $this->paginateDetails($pdf, $details, 106 - $tableY - 14);
        $pageCount = count($pages);

        foreach ($pages as $index => $rows) {
            if ($index > 0) {
                $pdf->AddPage();
                $this->drawPilotWatermark($pdf, $invoice);
                $headerBottom = $this->drawHeader($pdf, $invoice, $header);
                $customerBottom = $this->drawCustomer($pdf, $invoice, $header, $headerBottom);
            }

            $this->drawDetails($pdf, $rows, $customerBottom);
            if ($index === $pageCount - 1) {
                $this->drawTotals($pdf, $invoice, $header);
            }
            $this->drawFooter($pdf, $invoice, $header);
            $this->text($pdf, 8, 2, 194, 4, ($index + 1).' de '.$pageCount, 6, '', 'C');
        }

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

        $this->drawRollPilotWatermarks($pdf, $invoice, $height);
        $y = 7.0;

        $branchLabel = ((int) $invoice->branch_code === 0 || $invoice->pointOfSale?->branch?->is_main) ? 'CASA MATRIZ' : 'SUCURSAL '.$invoice->branch_code;
        $invoiceNumber = $invoice->invoice_number ?? $invoice->attempted_invoice_number;
        $issuedAt = $invoice->issued_at instanceof Carbon
            ? $invoice->issued_at
            : Carbon::parse((string) $invoice->issued_at);

        $fiscalTitle = $this->fiscalTitle($invoice);
        $titleHeight = max(5, $this->textHeight($pdf, 72, $fiscalTitle, 9, 'B'));
        $this->text($pdf, 4, $y, 72, $titleHeight, $fiscalTitle, 9, 'B', 'C');
        $y += $titleHeight;
        $subtitle = InvoiceDocumentSector::fiscalSubtitle((int) $invoice->document_sector_code);
        $subtitleHeight = max(4, $this->textHeight($pdf, 72, $subtitle, 7, 'B'));
        $this->text($pdf, 4, $y, 72, $subtitleHeight, $subtitle, 7, 'B', 'C');
        $y += $subtitleHeight + 1;

        $issuerName = (string) data_get($header, 'razonSocialEmisor', $company?->legal_name ?? $company?->name);
        $issuerNameHeight = max(4, $this->textHeight($pdf, 72, $issuerName, 7, 'B'));
        $this->text($pdf, 4, $y, 72, $issuerNameHeight, $issuerName, 7, 'B', 'C');
        $y += $issuerNameHeight;
        $this->text($pdf, 4, $y, 72, 4, $branchLabel, 6.5, 'B', 'C');
        $y += 4;
        $this->text($pdf, 4, $y, 72, 4, 'Punto de Venta '.$invoice->point_of_sale_code, 6, '', 'C');
        $y += 4;
        $address = (string) data_get($header, 'direccion', $company?->address ?? 'Sin direccion registrada');
        $addressHeight = max(5, $this->textHeight($pdf, 72, $address, 6));
        $this->text($pdf, 4, $y, 72, $addressHeight, $address, 6, '', 'C');
        $y += $addressHeight;
        $this->text($pdf, 4, $y, 72, 4, 'Telefono: '.((string) data_get($header, 'telefono', $company?->phone ?? '-')), 6, '', 'C');
        $y += 5;

        $y = $this->rollDivider($pdf, $y);
        $y = $this->rollCenteredPair($pdf, $y, 'NIT', (string) data_get($header, 'nitEmisor', $invoice->tax_id));
        $y = $this->rollCenteredPair($pdf, $y, 'FACTURA N°', (string) $invoiceNumber);
        $y = $this->rollCenteredPair($pdf, $y, 'CÓD. AUTORIZACIÓN', (string) data_get($header, 'cuf', $invoice->cuf), 5.2);
        $y = $this->rollDivider($pdf, $y);

        $y = $this->rollInlinePair($pdf, $y, 'NOMBRE/RAZÓN SOCIAL:', (string) data_get($header, 'nombreRazonSocial', $invoice->customer?->name ?? '-'));
        $y = $this->rollInlinePair($pdf, $y, 'NIT/CI/CEX:', (string) data_get($header, 'numeroDocumento', $invoice->customer?->document_number ?? '-'));
        $y = $this->rollInlinePair($pdf, $y, 'CÓD. CLIENTE:', (string) data_get($header, 'codigoCliente', $invoice->customer?->customer_code ?? '-'));
        $y = $this->rollInlinePair($pdf, $y, 'FECHA DE EMISIÓN:', $issuedAt->format('d/m/Y H:i:s'));

        $y = $this->rollDivider($pdf, $y);
        $this->text($pdf, 4, $y, 72, 4, 'DETALLE', 6.5, 'B', 'C');
        $y += 5;

        foreach ($details as $detail) {
            $description = (string) data_get($detail, 'descripcion', '-');
            $code = (string) data_get($detail, 'codigoProducto', data_get($detail, 'codigoProductoSin', '-'));
            $itemHeading = $code.' - '.$description;
            $headingHeight = max(4, $this->textHeight($pdf, 72, $itemHeading, 5.8, 'B'));
            $this->text($pdf, 4, $y, 72, $headingHeight, $itemHeading, 5.8, 'B');
            $y += $headingHeight;
            $this->text($pdf, 6, $y, 70, 4, 'Unidad de Medida: '.$this->unitLabel(data_get($detail, 'unidadMedida')), 5.2);
            $y += 4;
            $calculation = $this->number(data_get($detail, 'cantidad', 0), 2).' X '.$this->number(data_get($detail, 'precioUnitario', 0)).' - '.$this->number(data_get($detail, 'montoDescuento', 0));
            $this->text($pdf, 6, $y, 52, 4, $calculation, 5.2);
            $this->text($pdf, 58, $y, 18, 4, $this->number(data_get($detail, 'subTotal', 0)), 5.2, '', 'R');
            $y += 5;
            $pdf->SetLineStyle(['width' => .15, 'dash' => '1,1', 'color' => [70, 70, 70]]);
            $pdf->Line(4, $y, 76, $y);
            $pdf->SetLineStyle(['width' => .2, 'dash' => 0, 'color' => [0, 0, 0]]);
            $y += 2;
        }

        $y = $this->rollAmount($pdf, $y, 'SUB TOTAL Bs.', $invoice->subtotal_amount);
        $y = $this->rollAmount($pdf, $y, 'DESCUENTO Bs.', $invoice->discount_amount);
        $y = $this->rollAmount($pdf, $y, 'TOTAL Bs.', $invoice->total_amount);
        $y = $this->rollAmount($pdf, $y, 'MONTO GIFT CARD Bs.', data_get($header, 'montoGiftCard', 0));
        $y = $this->rollAmount($pdf, $y, 'MONTO A PAGAR Bs.', data_get($header, 'montoTotalMoneda', $invoice->total_amount), true, true);
        $y = $this->rollAmount($pdf, $y, 'IMPORTE BASE CRÉDITO FISCAL Bs.', $invoice->taxable_amount, true, true);
        $y += 2;

        $amountInWords = 'Son: '.$this->amountInWords((float) $invoice->total_amount);
        $wordsHeight = max(5, $this->textHeight($pdf, 72, $amountInWords, 6));
        $this->text($pdf, 4, $y, 72, $wordsHeight, $amountInWords, 6);
        $y += $wordsHeight + 2;
        $y = $this->rollDivider($pdf, $y);

        $legend = trim((string) data_get($header, 'leyenda', ''));
        if ($legend === '') {
            $legend = $this->randomLegend($invoice);
        }

        $legalLegend = 'ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY';
        $legalHeight = max(8, $this->textHeight($pdf, 72, $legalLegend, 5.2, 'B'));
        $this->text($pdf, 4, $y, 72, $legalHeight, $legalLegend, 5.2, 'B', 'C');
        $y += $legalHeight + 2;
        $legendHeight = max(7, $this->textHeight($pdf, 72, $legend, 5.2));
        $this->text($pdf, 4, $y, 72, $legendHeight, $legend, 5.2, '', 'C');
        $y += $legendHeight + 2;
        $representationLegend = $this->representationGraphicLegend($invoice);
        $representationHeight = max(8, $this->textHeight($pdf, 72, $representationLegend, 5.2));
        $this->text($pdf, 4, $y, 72, $representationHeight, $representationLegend, 5.2, '', 'C');
        $y += $representationHeight + 3;

        $pdf->write2DBarcode($this->verificationUrl($invoice, InvoicePrintFormat::Roll), 'QRCODE,M', 25, $y, 30, 30, [
            'border' => 0,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ]);
        $y += 32;
        $this->text($pdf, 4, $y, 72, 8, 'CUF: '.$invoice->cuf, 4.8, '', 'C');

        return $pdf->Output($this->filename($invoice), 'S');
    }

    private function drawHeader(TCPDF $pdf, SinInvoiceIssue $invoice, array $header): float
    {
        $company = $invoice->company;
        $branchLabel = ((int) $invoice->branch_code === 0 || $invoice->pointOfSale?->branch?->is_main) ? 'CASA MATRIZ' : 'SUCURSAL '.$invoice->branch_code;
        $invoiceNumber = $invoice->invoice_number ?? $invoice->attempted_invoice_number;

        $issuerName = (string) data_get($header, 'razonSocialEmisor', $company?->legal_name ?? $company?->name);
        $issuerName = $this->balancedTextLines($issuerName, 3);
        $issuerNameHeight = max(4, $this->textHeight($pdf, 92, $issuerName, 8.5, 'B'));
        $branchY = 8 + $issuerNameHeight + 3;
        $this->text($pdf, 8, 8, 92, $issuerNameHeight, $issuerName, 8.5, 'B', 'C');
        $this->text($pdf, 8, $branchY, 48, 4, $branchLabel, 6.5, 'B', 'C');
        $this->text($pdf, 8, $branchY + 4.5, 48, 4, 'Nro. Punto de Venta '.$invoice->point_of_sale_code, 6, '', 'C');
        $address = (string) data_get($header, 'direccion', $company?->address ?? 'Sin direccion registrada');
        $addressY = $branchY + 8.5;
        $addressHeight = max(7, $this->textHeight($pdf, 48, $address, 5.8));
        $municipalityY = $addressY + $addressHeight;
        $this->text($pdf, 8, $addressY, 48, $addressHeight, $address, 5.8, '', 'C');
        $this->text($pdf, 8, $municipalityY, 48, 4, (string) data_get($header, 'municipio', $company?->city ?? 'Bolivia'), 5.8, '', 'C');

        $this->text($pdf, 138, 8, 38, 4, 'NIT', 6.3, 'B');
        $this->text($pdf, 176, 8, 26, 4, (string) data_get($header, 'nitEmisor', $invoice->tax_id), 6.3);
        $this->text($pdf, 138, 13, 38, 4, 'FACTURA N°', 6.3, 'B');
        $this->text($pdf, 176, 13, 26, 4, (string) $invoiceNumber, 6.3);
        $this->text($pdf, 138, 18, 38, 4, 'CÓD. AUTORIZACIÓN', 6.1, 'B');
        $this->text($pdf, 176, 18, 26, 17, $this->authorizationCodeLines((string) data_get($header, 'cuf', $invoice->cuf)), 5.5);

        $fiscalTitle = $this->balancedTextLines($this->fiscalTitle($invoice), 2);
        $titleHeight = max(6, $this->textHeight($pdf, 194, $fiscalTitle, 10.5, 'B'));
        $titleY = $municipalityY + 6;
        $this->text($pdf, 8, $titleY, 194, $titleHeight, $fiscalTitle, 10.5, 'B', 'C');
        $subtitleY = $titleY + $titleHeight + 2;
        $this->text($pdf, 8, $subtitleY, 194, 5, InvoiceDocumentSector::fiscalSubtitle((int) $invoice->document_sector_code), 6.5, '', 'C');

        return $subtitleY + 5;
    }

    private function drawCustomer(TCPDF $pdf, SinInvoiceIssue $invoice, array $header, float $headerBottom): float
    {
        $issuedAt = $invoice->issued_at instanceof Carbon
            ? $invoice->issued_at
            : Carbon::parse((string) $invoice->issued_at);

        $firstRowY = max(53, $headerBottom + 1);
        $secondRowY = $firstRowY + 6;
        $this->text($pdf, 8, $firstRowY, 38, 4, 'Fecha:', 6.4, 'B');
        $this->text($pdf, 48, $firstRowY, 60, 4, $issuedAt->format('d/m/Y H:i:s'), 6.4);
        $this->text($pdf, 8, $secondRowY, 38, 4, 'Nombre/Razón Social:', 6.4, 'B');
        $this->text($pdf, 48, $secondRowY, 92, 4, (string) data_get($header, 'nombreRazonSocial', $invoice->customer?->name ?? '-'), 6.4);
        $this->text($pdf, 145, $firstRowY, 28, 4, 'NIT/CI/CEX:', 6.4, 'B');
        $this->text($pdf, 177, $firstRowY, 25, 4, (string) data_get($header, 'numeroDocumento', $invoice->customer?->document_number ?? '-'), 6.4);
        $this->text($pdf, 145, $secondRowY, 28, 4, 'Cód. Cliente:', 6.4, 'B');
        $this->text($pdf, 177, $secondRowY, 25, 4, (string) data_get($header, 'codigoCliente', $invoice->customer?->customer_code ?? '-'), 6.4);

        return $secondRowY + 5;
    }

    private function drawDetails(TCPDF $pdf, array $details, float $customerBottom): void
    {
        $x = 8;
        $y = max(68, $customerBottom + 1);
        $tableHeight = max(12, 106 - $y);
        $columns = [
            ['CÓDIGO', 'PRODUCTO /', 29, 'C'],
            ['CANTIDAD', '', 21, 'C'],
            ['UNIDAD DE', 'MEDIDA', 23, 'C'],
            ['DESCRIPCIÓN', '', 52, 'C'],
            ['PRECIO', 'UNITARIO', 25, 'C'],
            ['DESCUENTO', '', 22, 'C'],
            ['SUBTOTAL', '', 22, 'C'],
        ];

        $pdf->Rect($x, $y, 194, $tableHeight);
        $pdf->SetFont('helvetica', 'B', 5.5 + self::FONT_SIZE_INCREASE);
        $cursor = $x;

        foreach ($columns as [$line1, $line2, $width, $align]) {
            $pdf->MultiCell($width, 4, $line1."\n".$line2, 0, $align, false, 0, $cursor, $y + 2);
            $pdf->Line($cursor + $width, $y, $cursor + $width, $y + $tableHeight);
            $cursor += $width;
        }

        $pdf->Line($x, $y + 12, 202, $y + 12);

        $y += 14;
        foreach ($details as $row) {
            $cursor = $x;
            $pdf->SetFont('helvetica', '', 6 + self::FONT_SIZE_INCREASE);
            foreach ($row['cells'] as [$value, $width, $align]) {
                $pdf->MultiCell($width, $row['height'], $value, 0, $align, false, 0, $cursor, $y);
                $cursor += $width;
            }
            $y += $row['height'];
        }
    }

    /**
     * Measure every cell at the rendering font size. Long cells continue on the
     * next sheet rather than being clipped; numeric cells appear only once.
     */
    private function paginateDetails(TCPDF $pdf, array $details, float $capacity): array
    {
        if ($capacity < 6) {
            throw new \RuntimeException('El encabezado no deja espacio para el detalle de la factura.');
        }

        $pdf->SetFont('helvetica', '', 6 + self::FONT_SIZE_INCREASE);
        $pages = [];
        $rows = [];
        $used = 0.0;
        foreach ($details as $detail) {
            $cells = [
                [(string) data_get($detail, 'codigoProducto', '-'), 29, 'L'],
                [$this->number(data_get($detail, 'cantidad', 0), 2), 21, 'R'],
                [$this->unitLabel(data_get($detail, 'unidadMedida')), 23, 'L'],
                [(string) data_get($detail, 'descripcion', '-'), 52, 'L'],
                [$this->number(data_get($detail, 'precioUnitario', 0)), 25, 'R'],
                [$this->number(data_get($detail, 'montoDescuento', 0)), 22, 'R'],
                [$this->number(data_get($detail, 'subTotal', 0)), 22, 'R'],
            ];

            do {
                $fragment = [];
                $remaining = [];
                $height = 6.0;
                foreach ($cells as [$value, $width, $align]) {
                    $length = mb_strlen($value);
                    $low = 0;
                    $high = $length;
                    while ($low < $high) {
                        $middle = (int) ceil(($low + $high) / 2);
                        if ($pdf->getStringHeight($width, mb_substr($value, 0, $middle)) <= $capacity) {
                            $low = $middle;
                        } else {
                            $high = $middle - 1;
                        }
                    }
                    if ($length > 0 && $low === 0) {
                        throw new \RuntimeException('No se puede imprimir una celda del detalle en media hoja.');
                    }
                    if ($low < $length) {
                        $space = mb_strrpos(mb_substr($value, 0, $low), ' ');
                        if ($space !== false && $space > 0) {
                            $low = $space + 1;
                        }
                    }
                    $text = mb_substr($value, 0, $low);
                    $fragment[] = [$text, $width, $align];
                    $remaining[] = [mb_substr($value, $low), $width, $align];
                    $height = max($height, $pdf->getStringHeight($width, $text));
                }
                if ($used + $height > $capacity && $rows !== []) {
                    $pages[] = $rows;
                    $rows = [];
                    $used = 0.0;
                }
                $rows[] = ['cells' => $fragment, 'height' => $height];
                $used += $height;
                $cells = $remaining;
            } while (array_filter($cells, static fn (array $cell): bool => $cell[0] !== '') !== []);
        }
        if ($rows !== [] || $pages === []) {
            $pages[] = $rows;
        }

        return $pages;
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
        $qrUrl = $this->verificationUrl($invoice, InvoicePrintFormat::HalfPage);

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
        $pdf->SetFont('helvetica', $style, $size + self::FONT_SIZE_INCREASE);
        $pdf->MultiCell($w, $h, $text, 0, $align, false, 1, $x, $y);
    }

    private function textHeight(TCPDF $pdf, float $width, string $text, float $size, string $style = ''): float
    {
        $pdf->SetFont('helvetica', $style, $size + self::FONT_SIZE_INCREASE);

        return $pdf->getStringHeight($width, $text, false, true, '', 1);
    }

    private function authorizationCodeLines(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '-';
        }

        return implode("\n", str_split($code, (int) ceil(strlen($code) / 4)));
    }

    private function balancedTextLines(string $text, int $maximumLines): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if ($words === [] || $maximumLines < 2) {
            return trim($text);
        }

        $targetLength = (int) ceil((strlen(implode(' ', $words)) + 1) / $maximumLines);
        $lines = [''];

        foreach ($words as $word) {
            $lineIndex = count($lines) - 1;
            $candidate = trim($lines[$lineIndex].' '.$word);
            if ($lines[$lineIndex] !== '' && strlen($candidate) > $targetLength && count($lines) < $maximumLines) {
                $lines[] = $word;
            } else {
                $lines[$lineIndex] = $candidate;
            }
        }

        return implode("\n", $lines);
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

    private function rollDivider(TCPDF $pdf, float $y): float
    {
        $pdf->SetLineStyle(['width' => .2, 'dash' => '2,1.4', 'color' => [0, 0, 0]]);
        $pdf->Line(4, $y, 76, $y);
        $pdf->SetLineStyle(['width' => .2, 'dash' => 0, 'color' => [0, 0, 0]]);

        return $y + 3;
    }

    private function rollCenteredPair(TCPDF $pdf, float $y, string $label, string $value, float $valueSize = 6): float
    {
        $this->text($pdf, 4, $y, 72, 4, $label, 6, 'B', 'C');
        $y += 4;
        $height = max(4, $this->textHeight($pdf, 72, $value, $valueSize));
        $this->text($pdf, 4, $y, 72, $height, $value, $valueSize, '', 'C');

        return $y + $height + 1;
    }

    private function rollInlinePair(TCPDF $pdf, float $y, string $label, string $value): float
    {
        $labelWidth = 34.0;
        $valueHeight = max(4, $this->textHeight($pdf, 38, $value, 5.6));
        $this->text($pdf, 4, $y, $labelWidth, 4, $label, 5.6, 'B', 'R');
        $this->text($pdf, 39, $y, 37, $valueHeight, $value, 5.6);

        return $y + $valueHeight + .5;
    }

    private function rollAmount(TCPDF $pdf, float $y, string $label, mixed $amount, bool $bold = false, bool $shaded = false): float
    {
        $style = $bold ? 'B' : '';
        if ($shaded) {
            $pdf->SetFillColor(245, 245, 245);
            $pdf->Rect(4, $y, 72, 5, 'F');
        }
        $this->text($pdf, 14, $y + .4, 46, 4, $label, 5.8, $style, 'R');
        $this->text($pdf, 60, $y + .4, 16, 4, $this->number($amount), 5.8, $style, 'R');

        return $y + 5;
    }

    private function rollHeight(array $details): float
    {
        return max(360, 335 + (count($details) * 24));
    }

    private function drawRollPilotWatermarks(TCPDF $pdf, SinInvoiceIssue $invoice, float $height): void
    {
        if ($invoice->environment_code === SiatEnvironment::Production) {
            return;
        }

        $pdf->SetAlpha(.13);
        $pdf->SetTextColor(80, 80, 80);
        for ($y = 35.0; $y < $height - 30; $y += 75) {
            $this->text($pdf, 4, $y, 72, 12, 'SIN VALOR LEGAL', 22, 'B', 'C');
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetAlpha(1);
    }

    public function verificationUrl(SinInvoiceIssue $invoice, InvoicePrintFormat $format = InvoicePrintFormat::HalfPage): string
    {
        $header = $invoice->payload['cabecera'] ?? [];
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

    public function fiscalTitle(SinInvoiceIssue $invoice): string
    {
        if ((int) $invoice->document_sector_code !== InvoiceDocumentSector::ZERO_RATE) {
            return 'FACTURA';
        }

        return mb_strtoupper((string) (SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $invoice->company_id)
            ->where('catalog_key', 'tipos_documento_sector')
            ->where('classifier_code', (string) InvoiceDocumentSector::ZERO_RATE)
            ->value('description') ?: 'FACTURA DE TASA CERO POR VENTA DE LIBROS Y TRANSPORTE INTERNACIONAL DE CARGA'));
    }
}
