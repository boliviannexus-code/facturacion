<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateSinCatalogItemsStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null
            && ($this->user()?->can('siat-catalogs.sync') ?? false);
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['selected', 'all'])],
            'is_active' => ['required', 'boolean'],
            'items' => ['required_if:scope,selected', 'array'],
            'items.*' => ['integer'],
        ];
    }

    public function itemIds(): array
    {
        if ($this->validated('scope') === 'all') {
            return [];
        }

        return array_map('intval', $this->validated('items', []));
    }

    public function isActive(): bool
    {
        return (bool) $this->validated('is_active');
    }
}
