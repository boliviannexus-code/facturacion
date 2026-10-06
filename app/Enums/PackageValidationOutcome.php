<?php

declare(strict_types=1);

namespace App\Enums;

enum PackageValidationOutcome: string
{
    case Pending = 'PENDING';
    case Validated = 'VALIDATED';
    case Observed = 'OBSERVED';
    case Rejected = 'REJECTED';

    public function packageStatus(): InvoicePackageStatus
    {
        return match ($this) {
            self::Pending => InvoicePackageStatus::PendingValidation,
            self::Validated => InvoicePackageStatus::Validated,
            self::Observed => InvoicePackageStatus::Observed,
            self::Rejected => InvoicePackageStatus::Rejected,
        };
    }

    public function invoiceStatus(): ?InvoiceFiscalStatus
    {
        return match ($this) {
            self::Pending => null,
            self::Validated => InvoiceFiscalStatus::ValidatedAfterContingency,
            self::Observed => InvoiceFiscalStatus::Observed,
            self::Rejected => InvoiceFiscalStatus::Rejected,
        };
    }
}
