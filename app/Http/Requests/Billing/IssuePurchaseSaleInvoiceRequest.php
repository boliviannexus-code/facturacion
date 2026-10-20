<?php

namespace App\Http\Requests\Billing;

use App\Services\Billing\InvoiceDocumentSector;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssuePurchaseSaleInvoiceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('items'))) {
            $items = json_decode($this->input('items'), true);

            if (is_array($items)) {
                $this->merge(['items' => $items]);
            }
        }

        $this->merge([
            'issued_at' => now()->format('Y-m-d H:i:s'),
            'document_sector_code' => (int) ($this->input('document_sector_code') ?: InvoiceDocumentSector::PURCHASE_SALE),
            'card_number' => preg_replace('/\D+/', '', (string) $this->input('card_number')) ?: null,
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('invoices.issue') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = CompanyContext::id($this->user());

        return [
            'document_sector_code' => ['required', 'integer', Rule::in([
                InvoiceDocumentSector::PURCHASE_SALE,
                InvoiceDocumentSector::ZERO_RATE,
            ])],
            'sin_point_of_sale_id' => [
                'required',
                'integer',
                Rule::exists('sin_points_of_sale', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            'issuance_key' => ['nullable', 'uuid'],
            'economic_activity_code' => ['required', 'integer', 'min:1'],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            'issued_at' => ['required', 'date'],
            'payment_method_code' => [
                'required',
                'integer',
                Rule::exists('sin_catalog_items', 'classifier_code')
                    ->where('company_id', $companyId)
                    ->where('catalog_key', 'tipos_metodo_pago')
                    ->where('is_active', true),
            ],
            'card_number' => [
                Rule::requiredIf(fn (): bool => (int) $this->input('payment_method_code') === 2),
                'nullable',
                'digits:16',
            ],
            'currency_code' => ['required', 'integer', 'min:1'],
            'additional_discount_type' => ['required', Rule::in(['FIXED', 'PERCENTAGE'])],
            'total_discount' => ['nullable', 'numeric', 'min:0'],
            'additional_discount_percentage' => ['nullable', 'numeric', 'between:0,100', 'required_if:additional_discount_type,PERCENTAGE'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'gift_card_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.additional_description' => ['nullable', 'string', 'max:485'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['required', Rule::in(['FIXED', 'PERCENTAGE'])],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'between:0,100', 'required_if:items.*.discount_type,PERCENTAGE'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Agrega al menos un producto o servicio.',
            'items.min' => 'Agrega al menos un producto o servicio.',
            'sin_point_of_sale_id.required' => 'Selecciona la sucursal y punto de venta.',
            'customer_id.required' => 'Selecciona el cliente.',
            'payment_method_code.exists' => 'Selecciona un método de pago vigente del catálogo del SIN.',
            'card_number.required' => 'El número de tarjeta es obligatorio cuando el método de pago es Tarjeta.',
            'card_number.digits' => 'El número de tarjeta debe contener exactamente 16 dígitos.',
        ];
    }
}
