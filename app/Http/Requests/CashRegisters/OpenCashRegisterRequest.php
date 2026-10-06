<?php

namespace App\Http\Requests\CashRegisters;

use App\Models\CashRegister;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CashRegister::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'opening_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'opening_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'opening_amount.required' => 'Ingresa el monto inicial de la caja.',
            'opening_amount.min' => 'El monto inicial no puede ser negativo.',
            'opening_amount.max' => 'El monto inicial excede el limite permitido.',
            'opening_amount.decimal' => 'El monto inicial debe tener como maximo dos decimales.',
        ];
    }
}
