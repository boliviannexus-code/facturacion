<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SiatEnvironment;
use App\Services\Siat\SiatWsdlResolver;

final class InvoiceWsdlResolver
{
    public function resolve(int $documentSectorCode, int $companyId, SiatEnvironment $environment): string
    {
        return app(SiatWsdlResolver::class)->resolve($companyId, $environment, InvoiceDocumentSector::wsdlKey($documentSectorCode));
    }
}
