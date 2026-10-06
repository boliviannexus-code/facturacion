<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TranscribeManualCafcInvoiceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (is_string($items)) {
            $items = json_decode($items, true);
        }

        if (is_array($items)) {
            $items = array_map(static fn (array $item): array => [
                ...$item,
                'discount_amount' => $item['discount'] ?? $item['discount_amount'] ?? 0,
            ], $items);
            $subtotal = array_sum(array_map(static fn (array $item): float => max(0, (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0) - (float) ($item['discount_amount'] ?? 0)), $items));
            $enteredDiscount = (float) ($this->input('total_discount') ?? $this->input('discount_amount') ?? 0);
            $discount = $this->input('additional_discount_type') === 'PERCENTAGE'
                ? $subtotal * $enteredDiscount / 100
                : $enteredDiscount;

            $this->merge([
                'items' => $items,
                'discount_amount' => $discount,
                'total_amount' => max(0, $subtotal - $discount),
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('manual-cafc.transcribe') ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'payment_method_code' => ['required', 'integer', 'min:1'],
            'currency_code' => ['required', 'integer', 'min:1'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'document_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:10240'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
