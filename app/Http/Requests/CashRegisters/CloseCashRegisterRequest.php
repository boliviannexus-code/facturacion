<?php

namespace App\Http\Requests\CashRegisters;

use App\Models\CashRegister;
use Illuminate\Foundation\Http\FormRequest;

class CloseCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cashRegister = $this->route('cashRegister');

        return $cashRegister instanceof CashRegister
            && ($this->user()?->can('close', $cashRegister) ?? false);
    }

    public function rules(): array
    {
        return [
            'closing_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'closing_amount.required' => 'Ingresa el monto contado al cerrar la caja.',
            'closing_amount.min' => 'El monto de cierre no puede ser negativo.',
            'closing_amount.max' => 'El monto de cierre excede el limite permitido.',
            'closing_amount.decimal' => 'El monto de cierre debe tener como maximo dos decimales.',
        ];
    }
}
