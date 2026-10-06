<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceCommercialStatus: string
{
    case Draft = 'DRAFT';
    case Confirmed = 'CONFIRMED';
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';
}
