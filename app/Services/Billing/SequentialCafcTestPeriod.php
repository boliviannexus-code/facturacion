<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\SinCufd;
use App\Models\SinPointOfSale;
use Carbon\CarbonImmutable;
use RuntimeException;

final class SequentialCafcTestPeriod
{
    /** @return array{start: CarbonImmutable, end: CarbonImmutable, wait: int} */
    public function prepare(SinPointOfSale $point, int $invoiceCount): array
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $cufd = SinCufd::withoutGlobalScope('company')->successful()
            ->where('company_id', $point->company_id)
            ->where('sin_branch_id', $point->sin_branch_id)
            ->where('sin_point_of_sale_id', $point->id)
            ->whereNull('invalidated_at')
            ->whereNotNull('requested_at')
            ->where('expires_at', '>', $now)
            ->orderByDesc('requested_at')->orderByDesc('id')->first();
        if (! $cufd) {
            throw new RuntimeException('La prueba secuencial CAFC necesita un CUFD vigente para iniciar el siguiente ciclo.');
        }

        // Keep the whole simulated cycle strictly after the current CUFD was obtained.
        // Local timestamps have second precision, so do not reuse the replacement boundary.
        $earliestStart = $cufd->requested_at->startOfSecond()->addSeconds(2);
        $end = $now->subSecond();
        $start = $end->subSeconds($invoiceCount);
        $readyAt = $earliestStart->addSeconds($invoiceCount + 1);
        if ($cufd->expires_at->lte($readyAt->max($now))) {
            throw new RuntimeException('El CUFD vencerá antes de completar el intervalo de la prueba secuencial CAFC. Obtenga uno nuevo antes de continuar.');
        }

        return [
            'start' => $start,
            'end' => $end,
            'wait' => $start->lt($earliestStart) ? (int) $now->diffInSeconds($readyAt) : 0,
        ];
    }
}
