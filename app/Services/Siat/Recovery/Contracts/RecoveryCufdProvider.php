<?php

declare(strict_types=1);

namespace App\Services\Siat\Recovery\Contracts;

use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\Recovery\CufdAcquisitionResult;

interface RecoveryCufdProvider
{
    public function acquire(
        User $actor,
        SinPointOfSale $pointOfSale,
        SinApiToken $apiToken,
        SinAuthorization $authorization,
        SinCuis $cuis,
    ): CufdAcquisitionResult;
}
