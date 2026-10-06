<?php

namespace App\Http\Requests\Billing;

use App\Enums\InvoicePrintFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInvoicePrintSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.issue') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoice_print_format' => ['required', 'string', Rule::in(InvoicePrintFormat::values())],
        ];
    }
}
