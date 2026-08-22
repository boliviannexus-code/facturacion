<?php

namespace App\Http\Requests;

use App\Models\SinBranch;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSinBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null
            && ($this->user()?->can('siat-branches.manage') ?? false);
    }

    public function rules(): array
    {
        $companyId = CompanyContext::id($this->user());

        return [
            'branch_code' => [
                'required',
                'integer',
                'min:0',
                'max:2147483647',
                Rule::unique('sin_branches', 'branch_code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_main' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_code.unique' => 'Ya existe una sucursal con ese numero.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $companyId = CompanyContext::id($this->user());
            $isMain = $this->boolean('is_main');
            $branchCode = (int) $this->input('branch_code');

            if ($isMain && $branchCode !== 0) {
                $validator->errors()->add('branch_code', 'La casa matriz debe usar el numero de sucursal 0.');
            }

            if (! $isMain && $branchCode === 0) {
                $validator->errors()->add('branch_code', 'El numero 0 esta reservado para casa matriz.');
            }

            if ($isMain && SinBranch::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('is_main', true)->exists()) {
                $validator->errors()->add('is_main', 'Ya existe una casa matriz registrada.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'is_main' => $this->boolean('is_main'),
        ]);
    }
}
