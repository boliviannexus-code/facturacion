<?php

namespace App\Http\Requests\Parameters\Concerns;

use App\Models\Customer;
use App\Support\SiatIdentityDocumentTypes;
use Illuminate\Validation\Validator;

trait ValidatesSiatIdentityDocumentType
{
    protected function validateSiatIdentityDocumentType(Validator $validator, ?Customer $currentCustomer = null): void
    {
        $validator->after(function (Validator $validator) use ($currentCustomer): void {
            if ($validator->errors()->has('identity_document_type_code')) {
                return;
            }

            $companyId = $this->user()?->company_id;
            $code = (string) $this->input('identity_document_type_code');

            if (! SiatIdentityDocumentTypes::canBeUsed($companyId, $code, $currentCustomer)) {
                $validator->errors()->add(
                    'identity_document_type_code',
                    'Selecciona un tipo de documento identidad activo del catalogo SIAT sincronizado.'
                );

                return;
            }

            if ($validator->errors()->has('document_number')) {
                return;
            }

            $documentNumber = (string) $this->input('document_number');

            if (
                SiatIdentityDocumentTypes::requiresIdentityCardDigits($code)
                && ! preg_match('/^\d{5,10}$/', $documentNumber)
            ) {
                $validator->errors()->add(
                    'document_number',
                    'Para carnet de identidad, el numero de documento debe tener entre 5 y 10 digitos.'
                );

                return;
            }

            if (
                SiatIdentityDocumentTypes::requiresNitDigits($code)
                && ! preg_match('/^\d{7,13}$/', $documentNumber)
            ) {
                $validator->errors()->add(
                    'document_number',
                    'Para NIT, el numero de documento debe tener entre 7 y 13 digitos.'
                );
            }
        });
    }
}
