<?php

namespace App\Http\Requests\Parameters\Concerns;

use App\Models\Product;
use App\Support\SiatProductHomologation;
use Illuminate\Validation\Validator;

trait ValidatesSiatProductHomologation
{
    protected function validateSiatProductHomologation(Validator $validator, ?Product $currentProduct = null): void
    {
        $validator->after(function (Validator $validator) use ($currentProduct): void {
            if (
                $validator->errors()->has('measurement_unit_code')
                || $validator->errors()->has('economic_activity_code')
                || $validator->errors()->has('siat_product_code')
            ) {
                return;
            }

            $companyId = $this->user()?->company_id;
            $unitCode = (string) $this->input('measurement_unit_code');
            $activityCode = (string) $this->input('economic_activity_code');
            $productCode = (string) $this->input('siat_product_code');

            if (! SiatProductHomologation::measurementUnitCanBeUsed($companyId, $unitCode, $currentProduct)) {
                $validator->errors()->add(
                    'measurement_unit_code',
                    'Selecciona una unidad de medida activa del catalogo SIAT sincronizado.'
                );

                return;
            }

            if (
                $validator->errors()->has('economic_activity_code')
                || $validator->errors()->has('siat_product_code')
            ) {
                return;
            }

            if (! SiatProductHomologation::activityCanBeUsed($companyId, $activityCode, $currentProduct)) {
                $validator->errors()->add(
                    'economic_activity_code',
                    'Selecciona una actividad economica activa del catalogo SIAT sincronizado.'
                );

                return;
            }

            if (! SiatProductHomologation::productCanBeUsedForActivity($companyId, $activityCode, $productCode, $currentProduct)) {
                $validator->errors()->add(
                    'siat_product_code',
                    'Selecciona un producto SIAT activo que pertenezca a la actividad economica elegida.'
                );
            }
        });
    }
}
