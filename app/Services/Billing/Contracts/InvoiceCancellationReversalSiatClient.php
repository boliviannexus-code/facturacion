<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\Models\SinCufd;
use App\Models\SinInvoiceIssue;
use App\Services\Billing\InvoiceSiatResponse;

interface InvoiceCancellationReversalSiatClient
{
    public function reverse(SinInvoiceIssue $invoice, SinCufd $currentCufd): InvoiceSiatResponse;
}
