<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SaleStatus;
use App\Models\Sale;

final class SaleCommercialEffectService
{
    public function confirmLocked(Sale $sale): void
    {
        $now = now();

        $sale->forceFill([
            'sale_status' => SaleStatus::Invoiced,
            // Son marcadores idempotentes para los modulos de inventario/pagos aun no existentes.
            'inventory_applied_at' => $sale->inventory_applied_at ?? $now,
            'payment_registered_at' => $sale->payment_registered_at ?? $now,
            'commercial_confirmed_at' => $sale->commercial_confirmed_at ?? $now,
        ])->save();
    }
}
