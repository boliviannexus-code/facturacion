<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages;

use App\Models\SinInvoicePackage;

final readonly class PackageProcessResult
{
    public function __construct(
        public SinInvoicePackage $package,
        public bool $pending,
        public bool $retryable,
        public string $message,
    ) {}
}
