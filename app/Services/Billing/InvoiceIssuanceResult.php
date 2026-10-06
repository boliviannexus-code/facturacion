<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceIssuanceDecision;
use App\Models\SinInvoiceIssue;

final readonly class InvoiceIssuanceResult
{
    public function __construct(
        public InvoiceIssuanceDecision $decision,
        public ?SinInvoiceIssue $invoice,
        public string $message,
    ) {}

    public function issued(): bool
    {
        return $this->invoice !== null;
    }
}
