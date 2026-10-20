<?php

namespace App\Http\Requests;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSinCatalogItemStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CompanyContext::operatingCompanyId($this->user()) !== null
            && ($this->user()?->can('siat-catalogs.sync') ?? false);
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function isActive(): bool
    {
        return (bool) $this->validated('is_active');
    }
}
