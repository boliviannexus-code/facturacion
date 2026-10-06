<?php

namespace App\Services\Parameters;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SinCatalogItem;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\SiatProductHomologation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        $products = Product::query()
            ->with([
                'category:id,name',
            ])
            ->orderBy('description')
            ->paginate($perPage);

        $this->attachMeasurementUnitDescriptions($products->getCollection());

        return $products;
    }

    /**
     * @return array{categories: EloquentCollection<int, ProductCategory>, siatMeasurementUnits: Collection<int, array{code: string, description: string, is_active: bool}>, siatActivities: Collection<int, array{code: string, description: string, is_active: bool}>, siatProducts: Collection<int, array{activity_code: string, product_code: string, description: string, is_active: bool}>}
     */
    public function formOptions(?Product $product = null): array
    {
        return [
            'categories' => ProductCategory::query()
                ->where(function ($query) use ($product): void {
                    $query->where('is_active', true)
                        ->when(
                            $product,
                            fn ($query, Product $product) => $query->orWhere('id', $product->product_category_id)
                        );
                })
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            'siatMeasurementUnits' => SiatProductHomologation::measurementUnitOptions($product),
            'siatActivities' => SiatProductHomologation::activityOptions($product),
            'siatProducts' => SiatProductHomologation::productOptions($product),
        ];
    }

    public function create(User $user, array $data): Product
    {
        $data = CompanyContext::applyToData($this->normalize($data, true), $user);

        try {
            return DB::transaction(fn () => Product::query()->create($data));
        } catch (QueryException $exception) {
            $this->throwValidationException($exception);
        }
    }

    public function update(Product $product, array $data): Product
    {
        try {
            DB::transaction(fn () => $product->update($this->normalize($data)));
        } catch (QueryException $exception) {
            $this->throwValidationException($exception);
        }

        return $product->refresh();
    }

    public function delete(Product $product): bool
    {
        return (bool) $product->delete();
    }

    private function normalize(array $data, ?bool $defaultActive = null): array
    {
        foreach (['internal_code', 'description', 'economic_activity_code', 'unit_price'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        foreach (['product_category_id', 'measurement_unit_code', 'siat_product_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (int) $data[$field];
            }
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        } elseif ($defaultActive !== null) {
            $data['is_active'] = $defaultActive;
        }

        return $data;
    }

    private function throwValidationException(QueryException $exception): never
    {
        if (
            $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'products_company_internal_code_unique')
        ) {
            throw ValidationException::withMessages([
                'internal_code' => 'Ya existe un producto con ese codigo interno en esta empresa.',
            ]);
        }

        throw $exception;
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     */
    private function attachMeasurementUnitDescriptions(EloquentCollection $products): void
    {
        $companyIds = $products->pluck('company_id')->filter()->unique()->values();
        $unitCodes = $products->pluck('measurement_unit_code')->filter()->map(fn ($code): string => (string) $code)->unique()->values();

        if ($companyIds->isEmpty() || $unitCodes->isEmpty()) {
            return;
        }

        $units = SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->whereIn('company_id', $companyIds)
            ->where('catalog_key', SiatProductHomologation::MEASUREMENT_UNITS_CATALOG)
            ->where(function ($query) use ($unitCodes): void {
                $query->whereIn('classifier_code', $unitCodes)
                    ->orWhereIn(DB::raw("raw_data->>'codigoClasificador'"), $unitCodes);
            })
            ->get()
            ->mapWithKeys(fn (SinCatalogItem $item): array => [
                $item->company_id.'|'.(string) ($item->raw_data['codigoClasificador'] ?? $item->classifier_code) => $item->description,
            ]);

        $products->each(function (Product $product) use ($units): void {
            $product->setAttribute(
                'measurement_unit_description',
                $units->get($product->company_id.'|'.$product->measurement_unit_code)
            );
        });
    }
}
