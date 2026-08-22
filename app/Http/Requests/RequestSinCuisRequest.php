<?php

namespace App\Http\Requests;

use App\Models\SinPointOfSale;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestSinCuisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null
            && ($this->user()?->can('siat-cuis.request') ?? false);
    }

    public function rules(): array
    {
        $companyId = CompanyContext::id($this->user());

        return [
            'sin_point_of_sale_id' => [
                'required',
                'integer',
                Rule::exists('sin_points_of_sale', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'sin_point_of_sale_id.required' => 'Selecciona la sucursal y punto de venta.',
            'sin_point_of_sale_id.exists' => 'Selecciona un punto de venta activo valido.',
        ];
    }

    public function pointOfSale(): SinPointOfSale
    {
        return SinPointOfSale::query()
            ->with('branch')
            ->findOrFail((int) $this->validated('sin_point_of_sale_id'));
    }
}
