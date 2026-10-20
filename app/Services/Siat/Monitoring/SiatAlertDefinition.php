<?php

declare(strict_types=1);

namespace App\Services\Siat\Monitoring;

use App\Enums\SiatAlertSeverity;
use App\Enums\SiatAlertType;

final readonly class SiatAlertDefinition
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public int $companyId,
        public SiatAlertType $type,
        public SiatAlertSeverity $severity,
        public string $scopeKey,
        public string $title,
        public string $message,
        public int $conditionCount = 1,
        public ?int $branchId = null,
        public ?int $pointOfSaleId = null,
        public ?int $significantEventId = null,
        public ?int $invoicePackageId = null,
        public ?int $invoiceIssueId = null,
        public ?int $manualInvoiceId = null,
        public ?int $cufdId = null,
        public ?int $cafcRangeId = null,
        public ?int $authorizationId = null,
        public array $metadata = [],
    ) {}

    public function conditionKey(): string
    {
        return hash('sha256', implode('|', [$this->type->value, $this->companyId, $this->scopeKey]));
    }
}
