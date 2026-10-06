<?php

declare(strict_types=1);

namespace App\Services\Siat\Contracts;

use App\Models\SinApiToken;

interface SiatCommunicationClient
{
    public function verify(SinApiToken $configuration, int $timeoutSeconds): mixed;
}
