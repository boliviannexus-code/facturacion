<?php

namespace App\Http\Requests\Billing;

use App\Services\Billing\InvoiceDocumentSector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCafcRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cafc-ranges.manage') ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'cafc_code' => ['required', 'string', 'max:128'],
            'sin_branch_id' => ['required', 'integer', Rule::exists('sin_branches', 'id')->where('company_id', $companyId)],
            'sin_point_of_sale_id' => ['nullable', 'integer', Rule::exists('sin_points_of_sale', 'id')->where('company_id', $companyId)],
            'document_sector_code' => ['required', 'integer', Rule::in(array_keys(InvoiceDocumentSector::supported()))],
            'range_start' => ['required', 'integer', 'min:1'],
            'range_end' => ['required', 'integer', 'gte:range_start'],
            'authorized_from' => ['required', 'date'],
            'authorized_until' => ['required', 'date', 'after_or_equal:authorized_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
