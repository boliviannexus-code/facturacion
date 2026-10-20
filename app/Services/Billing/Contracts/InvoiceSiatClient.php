<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\Models\SinInvoiceIssue;
use App\Services\Billing\InvoiceSiatResponse;

interface InvoiceSiatClient
{
    public function send(SinInvoiceIssue $invoice, string $compressedXml): InvoiceSiatResponse;
}
