<?php

namespace App\Http\Requests\Parameters;

use App\Http\Requests\Parameters\Concerns\ValidatesSiatIdentityDocumentType;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends FormRequest
{
    use ValidatesSiatIdentityDocumentType;

    public function authorize(): bool
    {
        return $this->user()?->can('customers.edit') ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $customer = $this->route('customer');

        return [
            'identity_document_type_code' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'document_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('customers', 'document_number')
                    ->ignore($customer?->id)
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('identity_document_type_code', $this->input('identity_document_type_code'))
                        ->where('document_complement', $this->normalizedNullable('document_complement'))
                        ->whereNull('deleted_at')),
            ],
            'document_complement' => ['nullable', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        $this->validateSiatIdentityDocumentType($validator, $customer);
    }

    protected function prepareForValidation(): void
    {
        $this->mergeNormalizedStrings();
    }

    private function mergeNormalizedStrings(): void
    {
        $data = [];

        foreach ([
            'document_number',
            'document_complement',
            'name',
            'email',
            'phone',
            'address',
        ] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }

        if (($data['document_complement'] ?? null) === '') {
            $data['document_complement'] = null;
        }

        $this->merge($data);
    }

    private function normalizedNullable(string $field): ?string
    {
        $value = $this->input($field);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
