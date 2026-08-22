<?php

namespace App\Http\Requests\Parameters;

use App\Http\Requests\Parameters\Concerns\ValidatesSiatProductHomologation;
use App\Models\Product;
use App\Rules\UniqueProductInternalCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use ValidatesSiatProductHomologation;

    public function authorize(): bool
    {
        return $this->user()?->can('products.edit') ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        /** @var Product|null $product */
        $product = $this->route('product');

        return [
            'product_category_id' => [
                'required',
                'integer',
                Rule::exists('product_categories', 'id')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->where(fn ($query) => $query
                            ->where('is_active', true)
                            ->orWhere('id', $product?->product_category_id))),
            ],
            'measurement_unit_code' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'internal_code' => [
                'required',
                'string',
                'max:120',
                new UniqueProductInternalCode($companyId, $product?->id),
            ],
            'description' => ['required', 'string', 'max:500'],
            'economic_activity_code' => ['required', 'string', 'max:50'],
            'siat_product_code' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,5', 'max:999999999999999.99999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_category_id.exists' => 'Selecciona una categoria activa de tu empresa.',
            'unit_price.decimal' => 'El precio unitario admite hasta 5 decimales.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        $this->validateSiatProductHomologation($validator, $product);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'internal_code' => $this->trimmed('internal_code'),
            'description' => $this->trimmed('description'),
            'economic_activity_code' => $this->trimmed('economic_activity_code'),
            'unit_price' => $this->trimmed('unit_price'),
        ]);
    }

    private function trimmed(string $field): mixed
    {
        $value = $this->input($field);

        return is_string($value) ? trim($value) : $value;
    }
}
