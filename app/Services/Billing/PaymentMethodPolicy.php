<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\SinCatalogItem;
use Illuminate\Support\Str;

final class PaymentMethodPolicy
{
    public function isGiftCard(int $companyId, int $paymentMethodCode): bool
    {
        $method = SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('catalog_key', 'tipos_metodo_pago')
            ->where('classifier_code', (string) $paymentMethodCode)
            ->active()
            ->first();

        return $method !== null && $this->catalogItemIsGiftCard($method);
    }

    public function catalogItemIsGiftCard(SinCatalogItem $method): bool
    {
        $description = Str::lower(Str::ascii((string) $method->description));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $description) ?? '';

        return str_contains($normalized, 'gift')
            || str_contains($normalized, 'tarjetaregalo');
    }
}
