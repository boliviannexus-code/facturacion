<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Support\CompanyContext;
use Illuminate\Validation\Rule;

final class StoreCafcContingencyRangeRequest extends StoreCafcRangeRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id($this->user());

        return [
            ...parent::rules(),
            'cafc_code' => [
                'required',
                'string',
                'max:128',
                Rule::unique('sin_cafc_ranges', 'cafc_code')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('document_sector_code', $this->input('document_sector_code'))
                    ->where('sin_branch_id', $this->input('sin_branch_id'))
                    ->where('sin_point_of_sale_id', $this->input('sin_point_of_sale_id'))),
            ],
            'sin_point_of_sale_id' => ['required', 'integer', Rule::exists('sin_points_of_sale', 'id')->where('company_id', $companyId)->where('is_active', true)],
        ];
    }
}
