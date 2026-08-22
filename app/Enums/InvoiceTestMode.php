<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceTestMode: string
{
    case Online = 'ONLINE';
    case OfflineContingency = 'OFFLINE_CONTINGENCY';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Emisión en línea',
            self::OfflineContingency => 'Fuera de línea por contingencia',
        };
    }
}
