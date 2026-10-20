<?php

namespace App\Services\Parameters;

use App\Models\Customer;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\SiatIdentityDocumentTypes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        $customers = Customer::query()
            ->orderBy('name')
            ->paginate($perPage);

        $documentTypes = SiatIdentityDocumentTypes::descriptionsFor(
            $customers->getCollection()->pluck('identity_document_type_code')
        );

        $customers->getCollection()->each(function (Customer $customer) use ($documentTypes): void {
            $customer->setAttribute(
                'identity_document_type_description',
                $documentTypes->get((string) $customer->identity_document_type_code)
            );
        });

        return $customers;
    }

    public function formOptions(?Customer $customer = null): array
    {
        return [
            'identityDocumentTypes' => SiatIdentityDocumentTypes::options($customer),
        ];
    }

    public function create(User $user, array $data): Customer
    {
        $data = CompanyContext::applyToData($this->normalize($data, true), $user);
        $data['customer_code'] = $this->nextCustomerCode(
            (int) $data['company_id'],
            (string) $data['document_number']
        );

        try {
            return Customer::query()->create($data);
        } catch (QueryException $exception) {
            $this->throwValidationException($exception);
        }
    }

    public function update(Customer $customer, array $data): Customer
    {
        unset($data['customer_code']);

        try {
            $customer->update($this->normalize($data));
        } catch (QueryException $exception) {
            $this->throwValidationException($exception);
        }

        return $customer->refresh();
    }

    public function delete(Customer $customer): bool
    {
        return (bool) $customer->delete();
    }

    private function normalize(array $data, ?bool $defaultActive = null): array
    {
        foreach ([
            'document_number',
            'document_complement',
            'customer_code',
            'name',
            'email',
            'phone',
            'address',
        ] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }

            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }

        if (array_key_exists('identity_document_type_code', $data)) {
            $data['identity_document_type_code'] = (int) $data['identity_document_type_code'];
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        } elseif ($defaultActive !== null) {
            $data['is_active'] = $defaultActive;
        }

        return $data;
    }

    private function nextCustomerCode(int $companyId, string $documentNumber): string
    {
        $base = $this->customerCodeBase($documentNumber);
        $candidate = $base;
        $suffix = 2;

        while ($this->customerCodeExists($companyId, $candidate)) {
            $tail = '-'.$suffix;
            $candidate = substr($base, 0, 120 - strlen($tail)).$tail;
            $suffix++;
        }

        return $candidate;
    }

    private function customerCodeBase(string $documentNumber): string
    {
        $document = str($documentNumber)
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '')
            ->limit(116, '')
            ->toString();

        return 'CLI-'.($document !== '' ? $document : 'CLIENTE');
    }

    private function customerCodeExists(int $companyId, string $customerCode): bool
    {
        return Customer::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('customer_code', $customerCode)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function throwValidationException(QueryException $exception): never
    {
        if ($exception->getCode() === '23505') {
            if (str_contains($exception->getMessage(), 'customers_company_customer_code_unique')) {
                throw ValidationException::withMessages([
                    'customer_code' => 'Ya existe un cliente con ese codigo cliente en esta empresa.',
                ]);
            }

            if (str_contains($exception->getMessage(), 'customers_company_document_unique')) {
                throw ValidationException::withMessages([
                    'document_number' => 'Ya existe un cliente con ese documento en esta empresa.',
                ]);
            }
        }

        throw $exception;
    }
}
