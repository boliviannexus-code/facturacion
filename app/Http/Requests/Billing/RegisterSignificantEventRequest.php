<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterSignificantEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.issue') ?? false;
    }

    public function rules(): array
    {
        return [
            'event_code' => [
                'required',
                'integer',
                Rule::exists('sin_catalog_items', 'classifier_code')
                    ->where('company_id', $this->user()?->company_id)
                    ->where('catalog_key', 'eventos_significativos')
                    ->where('is_active', true),
            ],
            'description' => ['required', 'string', 'max:500'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at', 'before_or_equal:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'event_code.required' => 'Selecciona el evento significativo.',
            'event_code.exists' => 'Selecciona un evento significativo vigente del catalogo SIN.',
            'description.required' => 'Describe la contingencia.',
            'ended_at.after' => 'La fecha final debe ser posterior al inicio del evento.',
            'ended_at.before_or_equal' => 'La fecha final no puede estar en el futuro.',
        ];
    }
}
