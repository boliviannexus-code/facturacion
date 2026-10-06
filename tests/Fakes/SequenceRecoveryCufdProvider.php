<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\Recovery\Contracts\RecoveryCufdProvider;
use App\Services\Siat\Recovery\CufdAcquisitionResult;
use RuntimeException;

final class SequenceRecoveryCufdProvider implements RecoveryCufdProvider
{
    public int $calls = 0;

    /** @param array<int, CufdAcquisitionResult> $results */
    public function __construct(private array $results) {}

    public function acquire(
        User $actor,
        SinPointOfSale $pointOfSale,
        SinApiToken $apiToken,
        SinAuthorization $authorization,
        SinCuis $cuis,
    ): CufdAcquisitionResult {
        $this->calls++;

        return array_shift($this->results)
            ?? throw new RuntimeException('No existe una respuesta CUFD simulada.');
    }
}
