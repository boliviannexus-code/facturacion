<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Sale;
use App\Services\Billing\Contracts\InvoiceXmlSigner;

final class ComputerizedOnlineXmlSigner implements InvoiceXmlSigner
{
    public function sign(string $xml, Sale $sale): string
    {
        // La modalidad computarizada en linea no aplica firma digital al XML.
        return $xml;
    }
}
