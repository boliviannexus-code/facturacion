<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceIssuanceDecision: string
{
    case Online = 'ONLINE';
    case OfflineDigital = 'OFFLINE_DIGITAL';
    case ManualCafcRequired = 'MANUAL_CAFC_REQUIRED';
    case Blocked = 'BLOCKED';
}
