<?php

declare(strict_types=1);

namespace App\Services\Siat\Recovery;

use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\Recovery\Contracts\RecoveryCufdProvider;
use App\Services\Siat\SiatCufdService;
use Throwable;

final readonly class SiatRecoveryCufdProvider implements RecoveryCufdProvider
{
    public function __construct(private SiatCufdService $cufdService) {}

    public function acquire(
        User $actor,
        SinPointOfSale $pointOfSale,
        SinApiToken $apiToken,
        SinAuthorization $authorization,
        SinCuis $cuis,
    ): CufdAcquisitionResult {
        try {
            $cufd = $this->cufdService->requestWithConfiguration(
                $actor,
                $pointOfSale,
                $apiToken,
                $authorization,
                $cuis,
            );

            return new CufdAcquisitionResult(
                successful: $cufd->transaccion === true
                    && filled($cufd->cufd_code)
                    && $cufd->expires_at?->isFuture() === true,
                cufd: $cufd,
                message: (string) ($cufd->message ?: 'SIAT no devolvio un CUFD utilizable.'),
            );
        } catch (Throwable $exception) {
            return new CufdAcquisitionResult(
                successful: false,
                cufd: null,
                message: $exception->getMessage(),
            );
        }
    }
}
