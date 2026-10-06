<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\InvoiceDocumentSector;
use Tests\TestCase;

class InvoiceDocumentSectorTest extends TestCase
{
    public function test_each_supported_sector_uses_its_siat_invoice_document_type(): void
    {
        $this->assertSame(1, InvoiceDocumentSector::invoiceDocumentTypeCode(InvoiceDocumentSector::PURCHASE_SALE));
        $this->assertSame(2, InvoiceDocumentSector::invoiceDocumentTypeCode(InvoiceDocumentSector::ZERO_RATE));
    }
}
