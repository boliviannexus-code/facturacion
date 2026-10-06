<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\SinCafcRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCafcCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cafc-ranges.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var SinCafcRange|null $range */
        $range = $this->route('cafcRange');

        return [
            'cafc_code' => [
                'required',
                'string',
                'max:128',
                Rule::unique('sin_cafc_ranges', 'cafc_code')->withoutTrashed()
                    ->ignore($range?->id)
                    ->where(fn ($query) => $query
                        ->where('company_id', $range?->company_id)
                        ->where('document_sector_code', $range?->document_sector_code)
                        ->where('sin_branch_id', $range?->sin_branch_id)
                        ->where('sin_point_of_sale_id', $range?->sin_point_of_sale_id)),
            ],
        ];
    }
}
