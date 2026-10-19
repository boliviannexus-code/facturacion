<?php

namespace App\Http\Requests;

use App\Models\SinApiToken;
use Illuminate\Foundation\Http\FormRequest;

class SaveSinApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null
            && ($this->user()?->can('sin-api-tokens.manage') ?? false);
    }

    public function rules(): array
    {
        $apiTokenRequired = SinApiToken::query()->exists() ? 'nullable' : 'required';

        return [
            'api_token' => [$apiTokenRequired, 'string', 'max:4096'],
            'wsdl_url' => ['exclude'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_token.required' => 'Ingresa el token API devuelto por la plataforma de impuestos.',
            'starts_at.required' => 'Ingresa la fecha de inicio de vigencia.',
            'ends_at.required' => 'Ingresa la fecha de fin de vigencia.',
            'ends_at.after_or_equal' => 'La fecha fin debe ser igual o posterior a la fecha inicio.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach (['api_token', 'starts_at', 'ends_at'] as $field) {
            $value = $this->input($field);
            $data[$field] = is_string($value) ? trim($value) : $value;
        }

        if ($data['api_token'] === '') {
            $data['api_token'] = null;
        }

        $this->merge($data);
    }
}
