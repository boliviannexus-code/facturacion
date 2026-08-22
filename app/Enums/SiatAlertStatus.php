<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatAlertStatus: string
{
    case Active = 'ACTIVE';
    case Resolved = 'RESOLVED';
}
