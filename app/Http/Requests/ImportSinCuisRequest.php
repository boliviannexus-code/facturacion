<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ImportSinCuisRequest extends RequestSinCuisRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'cuis_code' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'cuis_code.required' => 'Ingresa el CUIS vigente registrado en el SIN.',
            'cuis_code.regex' => 'El CUIS solo puede contener letras y números.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cuis_code' => strtoupper(trim((string) $this->input('cuis_code'))),
        ]);
    }
}
