<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCafcContingencyInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manual-cafc.use') ?? false;
    }

    public function rules(): array
    {
        $companyId = CompanyContext::id($this->user());

        return [
            'sin_point_of_sale_id' => ['required', 'integer', Rule::exists('sin_points_of_sale', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'manual_invoice_number' => ['required', 'integer', 'min:1'],
            'issued_manually_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'manual_invoice_number.required' => 'Indica el número de la factura física.',
        ];
    }
}
