<?php

namespace App\Services\Parameters;

use App\Models\ProductCategory;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class ProductCategoryService
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return ProductCategory::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(User $user, array $data): ProductCategory
    {
        $data = CompanyContext::applyToData($this->normalize($data, true), $user);

        try {
            return ProductCategory::query()->create($data);
        } catch (QueryException $exception) {
            $this->throwValidationException($exception);
        }
    }

    public function update(ProductCategory $category, array $data): ProductCategory
    {
        try {
            $category->update($this->normalize($data));
        } catch (QueryException $exception) {
            $this->throwValidationException($exception);
        }

        return $category->refresh();
    }

    public function delete(ProductCategory $category): bool
    {
        if ($category->products()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'No puedes eliminar una categoria utilizada por productos.',
            ]);
        }

        return (bool) $category->delete();
    }

    private function normalize(array $data, ?bool $defaultActive = null): array
    {
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        if (($data['description'] ?? null) === '') {
            $data['description'] = null;
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
            && str_contains($exception->getMessage(), 'product_categories_company_name_unique')
        ) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe una categoria con ese nombre en esta empresa.',
            ]);
        }

        throw $exception;
    }
}
