<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class RegularizeSignificantEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contingencies.events.retry') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirmation' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indica el motivo de la regularización administrativa.',
            'reason.min' => 'El motivo debe tener al menos 10 caracteres.',
            'confirmation.accepted' => 'Confirma que revisaste el resultado anterior antes de reenviar.',
        ];
    }
}
