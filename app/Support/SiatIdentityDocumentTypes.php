<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\SinCatalogItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class SiatIdentityDocumentTypes
{
    public const CATALOG = 'tipos_documento_identidad';

    public const IDENTITY_CARD_CODE = '1';

    public const NIT_CODE = '5';

    /**
     * @return Collection<int, array{code: string, description: string, is_active: bool}>
     */
    public static function options(?Customer $customer = null): Collection
    {
        $currentCode = $customer?->identity_document_type_code !== null
            ? (string) $customer->identity_document_type_code
            : null;

        $types = SinCatalogItem::query()
            ->where('catalog_key', self::CATALOG)
            ->where(function ($query) use ($currentCode): void {
                $query->where('is_active', true)
                    ->when($currentCode, fn ($query, string $currentCode) => $query
                        ->orWhere('classifier_code', $currentCode)
                        ->orWhereRaw("raw_data->>'codigoClasificador' = ?", [$currentCode]));
            })
            ->orderByRaw("nullif(classifier_code, '')::integer nulls last")
            ->orderBy('description')
            ->get()
            ->map(fn (SinCatalogItem $item): array => [
                'code' => (string) (Arr::get($item->raw_data, 'codigoClasificador') ?? $item->classifier_code ?? ''),
                'description' => $item->description ?: (string) Arr::get($item->raw_data, 'descripcion', ''),
                'is_active' => (bool) $item->is_active,
            ])
            ->filter(fn (array $type): bool => $type['code'] !== '')
            ->unique('code')
            ->values();

        if ($currentCode && ! $types->contains('code', $currentCode)) {
            $types->push([
                'code' => $currentCode,
                'description' => 'Codigo actual sin catalogo sincronizado',
                'is_active' => false,
            ]);
        }

        return $types;
    }

    public static function canBeUsed(?int $companyId, string $code, ?Customer $currentCustomer = null): bool
    {
        if ($currentCustomer && (string) $currentCustomer->identity_document_type_code === $code) {
            return true;
        }

        if ($companyId === null) {
            return false;
        }

        return SinCatalogItem::query()
            ->forCompany($companyId)
            ->where('catalog_key', self::CATALOG)
            ->active()
            ->where(function ($query) use ($code): void {
                $query->where('classifier_code', $code)
                    ->orWhereRaw("raw_data->>'codigoClasificador' = ?", [$code]);
            })
            ->exists();
    }

    public static function requiresIdentityCardDigits(string $code): bool
    {
        return $code === self::IDENTITY_CARD_CODE;
    }

    public static function requiresNitDigits(string $code): bool
    {
        return $code === self::NIT_CODE;
    }

    /**
     * @param iterable<int|string> $codes
     * @return Collection<string, string>
     */
    public static function descriptionsFor(iterable $codes): Collection
    {
        $codes = collect($codes)
            ->filter(fn ($code): bool => $code !== null && $code !== '')
            ->map(fn ($code): string => (string) $code)
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        return SinCatalogItem::query()
            ->where('catalog_key', self::CATALOG)
            ->whereIn('classifier_code', $codes)
            ->get()
            ->mapWithKeys(function (SinCatalogItem $item): array {
                $code = (string) (Arr::get($item->raw_data, 'codigoClasificador') ?? $item->classifier_code);

                return [$code => $item->description ?: (string) Arr::get($item->raw_data, 'descripcion', '')];
            });
    }
}
