<?php

namespace App\Rules;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueProductInternalCode implements ValidationRule
{
    public function __construct(
        private readonly ?int $companyId,
        private readonly ?int $ignoreProductId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->companyId === null || ! is_string($value)) {
            return;
        }

        $exists = Product::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $this->companyId)
            ->whereRaw('lower(internal_code) = lower(?)', [$value])
            ->when(
                $this->ignoreProductId,
                fn ($query, int $productId) => $query->where('id', '!=', $productId),
            )
            ->exists();

        if ($exists) {
            $fail('Ya existe un producto con ese codigo interno en esta empresa.');
        }
    }
}
