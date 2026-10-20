<?php

declare(strict_types=1);

namespace App\Services\Siat\Contracts;

use App\Enums\SiatEnvironment;
use App\Models\SinApiToken;

interface SiatCommunicationClient
{
    public function verify(SinApiToken $configuration, int $timeoutSeconds, ?SiatEnvironment $environment = null): mixed;
}
