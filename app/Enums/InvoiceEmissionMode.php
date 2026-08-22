<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceEmissionMode: string
{
    case Online = 'ONLINE';
    case OfflineDigital = 'OFFLINE_DIGITAL';
    case ManualCafc = 'MANUAL_CAFC';
    case PortalWeb = 'PORTAL_WEB';
    case Blocked = 'BLOCKED';
}
