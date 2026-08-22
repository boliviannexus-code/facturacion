<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\Models\SinCufd;
use App\Models\SinInvoiceIssue;
use App\Services\Billing\InvoiceSiatResponse;

interface InvoiceCancellationSiatClient
{
    public function cancel(SinInvoiceIssue $invoice, SinCufd $currentCufd, int $reasonCode): InvoiceSiatResponse;
}
