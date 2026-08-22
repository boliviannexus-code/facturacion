<?php

namespace App\Support;

use App\Models\Product;
use App\Models\SinCatalogItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class SiatProductHomologation
{
    public const ACTIVITIES_CATALOG = 'actividades';

    public const PRODUCTS_CATALOG = 'productos_servicios';

    public const MEASUREMENT_UNITS_CATALOG = 'unidades_medida';

    /**
     * @return Collection<int, array{code: string, description: string, is_active: bool}>
     */
    public static function activityOptions(?Product $product = null): Collection
    {
        $currentCode = $product?->economic_activity_code;

        $activities = SinCatalogItem::query()
            ->where('catalog_key', self::ACTIVITIES_CATALOG)
            ->where(function ($query) use ($currentCode): void {
                $query->where('is_active', true)
                    ->when($currentCode, fn ($query, string $currentCode) => $query
                        ->orWhereRaw("raw_data->>'codigoCaeb' = ?", [$currentCode]));
            })
            ->orderByRaw("raw_data->>'codigoCaeb'")
            ->get()
            ->map(fn (SinCatalogItem $item): array => [
                'code' => (string) Arr::get($item->raw_data, 'codigoCaeb', ''),
                'description' => $item->description ?: (string) Arr::get($item->raw_data, 'descripcion', ''),
                'is_active' => (bool) $item->is_active,
            ])
            ->filter(fn (array $activity): bool => $activity['code'] !== '')
            ->unique('code')
            ->values();

        if ($currentCode && ! $activities->contains('code', (string) $currentCode)) {
            $activities->push([
                'code' => (string) $currentCode,
                'description' => 'Codigo actual sin catalogo sincronizado',
                'is_active' => false,
            ]);
        }

        return $activities;
    }

    /**
     * @return Collection<int, array{activity_code: string, product_code: string, description: string, is_active: bool}>
     */
    public static function productOptions(?Product $product = null): Collection
    {
        $currentActivityCode = $product?->economic_activity_code;
        $currentProductCode = $product?->siat_product_code !== null
            ? (string) $product->siat_product_code
            : null;

        $products = SinCatalogItem::query()
            ->where('catalog_key', self::PRODUCTS_CATALOG)
            ->where(function ($query) use ($currentActivityCode, $currentProductCode): void {
                $query->where('is_active', true)
                    ->when(
                        $currentActivityCode && $currentProductCode,
                        fn ($query) => $query->orWhere(function ($query) use ($currentActivityCode, $currentProductCode): void {
                            $query->whereRaw("raw_data->>'codigoActividad' = ?", [$currentActivityCode])
                                ->whereRaw("raw_data->>'codigoProducto' = ?", [$currentProductCode]);
                        })
                    );
            })
            ->orderByRaw("raw_data->>'codigoActividad'")
            ->orderBy('description')
            ->get()
            ->map(fn (SinCatalogItem $item): array => [
                'activity_code' => (string) Arr::get($item->raw_data, 'codigoActividad', ''),
                'product_code' => (string) Arr::get($item->raw_data, 'codigoProducto', ''),
                'description' => $item->description ?: (string) Arr::get($item->raw_data, 'descripcionProducto', ''),
                'is_active' => (bool) $item->is_active,
            ])
            ->filter(fn (array $product): bool => $product['activity_code'] !== '' && $product['product_code'] !== '')
            ->unique(fn (array $product): string => $product['activity_code'].'|'.$product['product_code'])
            ->values();

        if (
            $currentActivityCode
            && $currentProductCode
            && ! $products->contains(fn (array $product): bool => $product['activity_code'] === (string) $currentActivityCode
                && $product['product_code'] === (string) $currentProductCode)
        ) {
            $products->push([
                'activity_code' => (string) $currentActivityCode,
                'product_code' => (string) $currentProductCode,
                'description' => 'Codigo actual sin catalogo sincronizado',
                'is_active' => false,
            ]);
        }

        return $products;
    }

    /**
     * @return Collection<int, array{code: string, description: string, is_active: bool}>
     */
    public static function measurementUnitOptions(?Product $product = null): Collection
    {
        $currentCode = $product?->measurement_unit_code !== null
            ? (string) $product->measurement_unit_code
            : null;

        $units = SinCatalogItem::query()
            ->where('catalog_key', self::MEASUREMENT_UNITS_CATALOG)
            ->where(function ($query) use ($currentCode): void {
                $query->where('is_active', true)
                    ->when($currentCode, fn ($query, string $currentCode) => $query
                        ->orWhere('classifier_code', $currentCode)
                        ->orWhereRaw("raw_data->>'codigoClasificador' = ?", [$currentCode]));
            })
            ->orderByRaw("(nullif(classifier_code, ''))::int nulls last")
            ->orderBy('description')
            ->get()
            ->map(fn (SinCatalogItem $item): array => [
                'code' => (string) (Arr::get($item->raw_data, 'codigoClasificador') ?? $item->classifier_code ?? ''),
                'description' => $item->description ?: (string) Arr::get($item->raw_data, 'descripcion', ''),
                'is_active' => (bool) $item->is_active,
            ])
            ->filter(fn (array $unit): bool => $unit['code'] !== '')
            ->unique('code')
            ->values();

        if ($currentCode && ! $units->contains('code', $currentCode)) {
            $units->push([
                'code' => $currentCode,
                'description' => 'Codigo actual sin catalogo sincronizado',
                'is_active' => false,
            ]);
        }

        return $units;
    }

    public static function activityCanBeUsed(?int $companyId, string $activityCode, ?Product $currentProduct = null): bool
    {
        if ($currentProduct && (string) $currentProduct->economic_activity_code === $activityCode) {
            return true;
        }

        if ($companyId === null) {
            return false;
        }

        return SinCatalogItem::query()
            ->forCompany($companyId)
            ->where('catalog_key', self::ACTIVITIES_CATALOG)
            ->active()
            ->whereRaw("raw_data->>'codigoCaeb' = ?", [$activityCode])
            ->exists();
    }

    public static function measurementUnitCanBeUsed(?int $companyId, string $unitCode, ?Product $currentProduct = null): bool
    {
        if ($currentProduct && (string) $currentProduct->measurement_unit_code === $unitCode) {
            return true;
        }

        if ($companyId === null) {
            return false;
        }

        return SinCatalogItem::query()
            ->forCompany($companyId)
            ->where('catalog_key', self::MEASUREMENT_UNITS_CATALOG)
            ->active()
            ->where(function ($query) use ($unitCode): void {
                $query->where('classifier_code', $unitCode)
                    ->orWhereRaw("raw_data->>'codigoClasificador' = ?", [$unitCode]);
            })
            ->exists();
    }

    public static function productCanBeUsedForActivity(
        ?int $companyId,
        string $activityCode,
        string $productCode,
        ?Product $currentProduct = null
    ): bool {
        if (
            $currentProduct
            && (string) $currentProduct->economic_activity_code === $activityCode
            && (string) $currentProduct->siat_product_code === $productCode
        ) {
            return true;
        }

        if ($companyId === null) {
            return false;
        }

        return SinCatalogItem::query()
            ->forCompany($companyId)
            ->where('catalog_key', self::PRODUCTS_CATALOG)
            ->active()
            ->whereRaw("raw_data->>'codigoActividad' = ?", [$activityCode])
            ->whereRaw("raw_data->>'codigoProducto' = ?", [$productCode])
            ->exists();
    }
}
