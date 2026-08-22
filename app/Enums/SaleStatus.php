<?php

declare(strict_types=1);

namespace App\Enums;

enum SaleStatus: string
{
    case Confirmed = 'CONFIRMED';
    case Invoiced = 'INVOICED';
    case Blocked = 'BLOCKED';
    case Cancelled = 'CANCELLED';
}
