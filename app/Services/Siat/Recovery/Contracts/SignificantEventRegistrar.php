<?php

declare(strict_types=1);

namespace App\Services\Siat\Recovery\Contracts;

use App\Services\Siat\Recovery\SignificantEventRegistrationRequest;
use App\Services\Siat\Recovery\SignificantEventRegistrationResult;

interface SignificantEventRegistrar
{
    public function register(SignificantEventRegistrationRequest $request): SignificantEventRegistrationResult;
}
