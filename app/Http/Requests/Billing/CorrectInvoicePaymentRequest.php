<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CorrectInvoicePaymentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['card_number' => preg_replace('/\D+/', '', (string) $this->input('card_number')) ?: null]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('invoices.issue') === true;
    }

    public function rules(): array
    {
        return [
            'payment_method_code' => ['required', 'integer', Rule::exists('sin_catalog_items', 'classifier_code')
                ->where('company_id', CompanyContext::id($this->user()))->where('catalog_key', 'tipos_metodo_pago')->where('is_active', true)],
            'card_number' => [Rule::requiredIf(fn (): bool => (int) $this->input('payment_method_code') === 2), 'nullable', 'digits:16'],
            'additional_discount_type' => ['required', Rule::in(['FIXED', 'PERCENTAGE'])],
            'total_discount' => ['required', 'numeric', 'min:0'],
            'additional_discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'gift_card_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_type' => ['required', Rule::in(['FIXED', 'PERCENTAGE'])],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
