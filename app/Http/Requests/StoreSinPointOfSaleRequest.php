<?php

namespace App\Http\Requests;

use App\Models\SinBranch;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreSinPointOfSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        return $this->user()?->company_id !== null
            && ($this->user()?->can('siat-branches.manage') ?? false)
            && $branch instanceof SinBranch
            && CompanyContext::belongsToUser($branch->company_id, $this->user());
    }

    public function rules(): array
    {
        return [
            'point_of_sale_type_code' => [
                'required',
                'integer',
                'between:1,6',
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'point_of_sale_type_code.between' => 'Selecciona un tipo de punto de venta admitido por el SIN.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'description' => is_string($this->input('description')) ? trim($this->input('description')) : $this->input('description'),
        ]);
    }
}
