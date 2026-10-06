<?php

namespace App\Http\Requests;

use App\Models\SinPointOfSale;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncSiatCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null
            && ($this->user()?->can('siat-catalogs.sync') ?? false);
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
            'sync_count' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'sin_point_of_sale_id.required' => 'Selecciona la sucursal y punto de venta para sincronizar.',
            'sin_point_of_sale_id.exists' => 'Selecciona un punto de venta activo valido.',
            'sync_count.required' => 'Ingresa cuantas veces se debe sincronizar.',
            'sync_count.integer' => 'La cantidad de sincronizaciones debe ser un numero entero.',
            'sync_count.min' => 'La cantidad minima de sincronizaciones es 1.',
            'sync_count.max' => 'La cantidad maxima de sincronizaciones por solicitud es 50.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sync_count' => $this->input('sync_count', 1),
        ]);
    }

    public function pointOfSale(): SinPointOfSale
    {
        return SinPointOfSale::query()
            ->with('branch')
            ->findOrFail((int) $this->validated('sin_point_of_sale_id'));
    }

    public function syncCount(): int
    {
        return (int) $this->validated('sync_count');
    }
}
