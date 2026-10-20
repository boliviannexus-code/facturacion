<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceCustomerNotificationType: string
{
    case Issued = 'ISSUED';
    case Cancelled = 'CANCELLED';
    case CancellationReversed = 'CANCELLATION_REVERSED';
}
