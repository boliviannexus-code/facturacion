<?php

namespace App\Http\Requests\Parameters;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\SinAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSinAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null
            && ($this->user()?->can('sin-authorizations.manage') ?? false);
    }

    public function rules(): array
    {
        $systemCodeRequired = SinAuthorization::query()->exists() ? 'nullable' : 'required';

        return [
            'tax_id' => ['required', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'legal_name' => ['required', 'string', 'max:255'],
            'system_code' => [$systemCodeRequired, 'string', 'max:255'],
            'environment_code' => ['required', 'integer', Rule::in(SiatEnvironment::values())],
            'modality_code' => ['required', 'integer', Rule::in(SiatModality::values())],
            'branch_code' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'point_of_sale_code' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'force_offline_emission' => ['sometimes', 'boolean'],
            'certificate_expires_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'tax_id.regex' => 'El NIT solo puede contener digitos.',
            'system_code.required' => 'Ingresa el codigo de sistema asignado por el SIN.',
            'environment_code.in' => 'Selecciona un ambiente SIAT valido.',
            'modality_code.in' => 'Selecciona una modalidad SIAT valida.',
            'branch_code.min' => 'El codigo de sucursal no puede ser negativo.',
            'point_of_sale_code.min' => 'El codigo de punto de venta no puede ser negativo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach ([
            'tax_id',
            'legal_name',
            'system_code',
            'branch_code',
            'point_of_sale_code',
            'certificate_expires_at',
        ] as $field) {
            $value = $this->input($field);
            $data[$field] = is_string($value) ? trim($value) : $value;
        }

        if ($data['system_code'] === '') {
            $data['system_code'] = null;
        }

        if ($data['point_of_sale_code'] === '') {
            $data['point_of_sale_code'] = null;
        }

        if ($data['certificate_expires_at'] === '') {
            $data['certificate_expires_at'] = null;
        }

        $this->merge($data);
    }
}
