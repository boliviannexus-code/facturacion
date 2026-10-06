<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\SinWsdlService;
use Illuminate\Support\Facades\Schema;

final class InvoiceWsdlResolver
{
    public function resolve(int $documentSectorCode, int $companyId): string
    {
        if (Schema::hasTable('sin_wsdl_services')) {
            $configured = SinWsdlService::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('key', InvoiceDocumentSector::wsdlKey($documentSectorCode))
                ->where('is_active', true)
                ->value('url');

            if (is_string($configured) && trim($configured) !== '') {
                return trim($configured);
            }
        }

        return InvoiceDocumentSector::defaultWsdl($documentSectorCode);
    }
}
