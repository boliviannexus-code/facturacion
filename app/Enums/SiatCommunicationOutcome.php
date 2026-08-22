<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatCommunicationOutcome: string
{
    case Available = 'AVAILABLE';
    case Unavailable = 'UNAVAILABLE';
    case Timeout = 'TIMEOUT';
    case InvalidConfiguration = 'INVALID_CONFIGURATION';
    case Error = 'ERROR';
}
