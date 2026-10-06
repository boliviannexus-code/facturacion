<?php

namespace App\Http\Requests\Billing;

use App\Support\CompanyContext;
use Illuminate\Validation\Rule;

class RegisterPointOfSaleSignificantEventRequest extends RegisterSignificantEventRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sin_point_of_sale_id' => [
                'required',
                'integer',
                Rule::exists('sin_points_of_sale', 'id')
                    ->where('company_id', CompanyContext::id($this->user()))
                    ->where('is_active', true),
            ],
        ];
    }
}
