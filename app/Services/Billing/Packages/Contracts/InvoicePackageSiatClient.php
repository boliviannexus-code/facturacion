<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages\Contracts;

use App\Models\SinInvoicePackage;
use App\Services\Billing\Packages\PackageReceptionResult;
use App\Services\Billing\Packages\PackageValidationResult;

interface InvoicePackageSiatClient
{
    public function send(SinInvoicePackage $package, string $archive): PackageReceptionResult;

    public function checkValidation(SinInvoicePackage $package): PackageValidationResult;
}
