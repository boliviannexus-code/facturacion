<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Models\SinInvoiceIssue;
use App\Models\User;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\SaleCreationService;
use Illuminate\Validation\ValidationException;

/**
 * Fachada de compatibilidad. Toda emision real pasa por InvoiceIssuanceService.
 */
class PurchaseSaleInvoiceIssueService
{
    public function __construct(
        private readonly SaleCreationService $sales,
        private readonly InvoiceIssuanceService $issuance,
    ) {}

    /** @param array<string, mixed> $data */
    public function issue(User $user, array $data): SinInvoiceIssue
    {
        $result = $this->issuance->issue($this->sales->create($user, $data));

        if (! $result->invoice) {
            throw ValidationException::withMessages(['invoice' => $result->message]);
        }

        return $result->invoice;
    }
}
