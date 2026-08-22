<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\SinSignificantEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterOpenSignificantEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contingencies.events.retry') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var SinSignificantEvent|null $event */
        $event = $this->route('event');

        return [
            'event_code' => [
                'required',
                'integer',
                Rule::exists('sin_catalog_items', 'classifier_code')
                    ->where('company_id', $event?->company_id)
                    ->where('catalog_key', 'eventos_significativos')
                    ->where('is_active', true),
            ],
            'description' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'event_code.required' => 'Selecciona uno de los eventos significativos de Impuestos.',
            'event_code.exists' => 'El evento seleccionado no pertenece al catálogo vigente de Impuestos.',
            'description.required' => 'Describe la causa real de la contingencia.',
        ];
    }
}
