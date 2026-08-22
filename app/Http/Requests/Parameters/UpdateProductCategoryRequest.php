<?php

namespace App\Http\Requests\Parameters;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('product-categories.edit') ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $category = $this->route('productCategory');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_categories', 'name')
                    ->ignore($category?->id)
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'description' => is_string($this->input('description'))
                ? trim($this->input('description'))
                : $this->input('description'),
        ]);
    }
}
