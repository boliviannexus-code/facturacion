<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordManualCafcInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manual-cafc.use') ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'cafc_range_id' => ['required', 'integer', Rule::exists('sin_cafc_ranges', 'id')->where('company_id', $companyId)],
            'sin_point_of_sale_id' => ['required', 'integer', Rule::exists('sin_points_of_sale', 'id')->where('company_id', $companyId)],
            'significant_event_id' => ['required', 'integer', Rule::exists('sin_significant_events', 'id')->where('company_id', $companyId)],
            'manual_invoice_number' => ['required', 'integer', 'min:1'],
            'issued_manually_at' => ['required', 'date', 'before_or_equal:now'],
            'operation' => ['required', Rule::in(['used', 'cancelled'])],
            'void_reason' => ['nullable', 'required_if:operation,cancelled', 'string', 'max:1000'],
        ];
    }
}
