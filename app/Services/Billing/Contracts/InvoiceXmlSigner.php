<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\Models\Sale;

interface InvoiceXmlSigner
{
    public function sign(string $xml, Sale $sale): string;
}
