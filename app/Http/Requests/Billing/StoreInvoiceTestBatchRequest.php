<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\InvoiceTestMode;
use App\Models\Product;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Models\SinPointOfSale;
use App\Services\Billing\InvoiceDocumentSector;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreInvoiceTestBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoice-tests.run') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyContext::id($this->user());

        return [
            'test_mode' => ['required', Rule::enum(InvoiceTestMode::class)],
            'document_sector_code' => ['required', 'integer', Rule::in([
                InvoiceDocumentSector::PURCHASE_SALE,
                InvoiceDocumentSector::ZERO_RATE,
            ])],
            'sin_branch_id' => ['required', 'integer', Rule::exists('sin_branches', 'id')
                ->where('company_id', $companyId)->where('is_active', true)],
            'sin_point_of_sale_id' => ['required', 'integer', Rule::exists('sin_points_of_sale', 'id')
                ->where('company_id', $companyId)->where('is_active', true)],
            'economic_activity_code' => ['required', 'integer'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')
                ->where('company_id', $companyId)->where('is_active', true)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')
                ->where('company_id', $companyId)->where('is_active', true)],
            'payment_method_code' => ['required', 'integer', 'not_in:2', Rule::exists('sin_catalog_items', 'classifier_code')
                ->where('company_id', $companyId)->where('catalog_key', 'tipos_metodo_pago')->where('is_active', true)],
            'currency_code' => ['required', 'integer', Rule::exists('sin_catalog_items', 'classifier_code')
                ->where('company_id', $companyId)->where('catalog_key', 'tipos_moneda')->where('is_active', true)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'invoice_count' => ['required', 'integer', 'between:1,500'],
            'invoices_per_cycle' => ['nullable', 'integer', 'between:1,500'],
            'event_code' => [
                Rule::requiredIf($this->input('test_mode') === InvoiceTestMode::OfflineContingency->value),
                'nullable', 'integer', Rule::exists('sin_catalog_items', 'classifier_code')
                    ->where('company_id', $companyId)->where('catalog_key', 'eventos_significativos')->where('is_active', true),
            ],
            'event_description' => [
                Rule::requiredIf($this->input('test_mode') === InvoiceTestMode::OfflineContingency->value),
                'nullable', 'string', 'max:500',
            ],
            'sin_cafc_range_id' => [
                Rule::requiredIf($this->usesManualCafc()),
                'nullable',
                'integer',
                Rule::exists('sin_cafc_ranges', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $companyId = CompanyContext::id($this->user());
            if ($this->input('test_mode') === InvoiceTestMode::OfflineContingency->value
                && (int) $this->input('invoice_count') > 10) {
                $validator->errors()->add('invoice_count', 'La prueba de contingencia admite entre 1 y 10 ciclos.');
            }
            if ($this->usesManualCafc()) {
                $range = SinCafcRange::query()->withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->whereKey((int) $this->input('sin_cafc_range_id'))
                    ->where('sin_branch_id', (int) $this->input('sin_branch_id'))
                    ->where('sin_point_of_sale_id', (int) $this->input('sin_point_of_sale_id'))
                    ->where('is_test_copy', false)
                    ->first();

                if (! $range) {
                    $validator->errors()->add('sin_cafc_range_id', 'Selecciona un CAFC vigente, sin uso y asignado al mismo punto de venta.');
                } elseif (($range->range_end - $range->range_start + 1) < (int) $this->input('invoices_per_cycle', 1)) {
                    $validator->errors()->add('invoices_per_cycle', 'La cantidad supera el tamaño total autorizado del CAFC.');
                }
            }
            $pointMatchesBranch = SinPointOfSale::query()->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->whereKey((int) $this->input('sin_point_of_sale_id'))
                ->where('sin_branch_id', (int) $this->input('sin_branch_id'))
                ->exists();

            if (! $pointMatchesBranch) {
                $validator->errors()->add('sin_point_of_sale_id', 'El punto de venta no pertenece a la sucursal seleccionada.');
            }

            $activityCode = (string) $this->input('economic_activity_code');
            $activityExists = SinCatalogItem::query()->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('catalog_key', 'actividades')
                ->where('is_active', true)
                ->where(function ($query) use ($activityCode): void {
                    $query->where('classifier_code', $activityCode)
                        ->orWhere('raw_data->codigoCaeb', $activityCode);
                })->exists();

            if (! $activityExists) {
                $validator->errors()->add('economic_activity_code', 'Selecciona una actividad económica vigente.');
            }

            if ((int) $this->input('document_sector_code') === InvoiceDocumentSector::ZERO_RATE) {
                $activityAllowed = SinCatalogItem::query()->withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->where('catalog_key', 'actividades_documento_sector')
                    ->where('is_active', true)
                    ->get(['classifier_code', 'raw_data'])
                    ->contains(fn (SinCatalogItem $item): bool => (int) data_get($item->raw_data, 'codigoDocumentoSector') === InvoiceDocumentSector::ZERO_RATE
                        && (string) data_get($item->raw_data, 'codigoActividad', $item->classifier_code) === $activityCode
                    );

                if (! $activityAllowed) {
                    $validator->errors()->add('economic_activity_code', 'La actividad debe estar habilitada para Factura Tasa Cero (sector 8).');
                }
            }

            $productMatchesActivity = Product::query()->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->whereKey((int) $this->input('product_id'))
                ->where('economic_activity_code', (int) $this->input('economic_activity_code'))
                ->exists();

            if (! $productMatchesActivity) {
                $validator->errors()->add('product_id', 'El producto no pertenece a la actividad económica seleccionada.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sin_branch_id.required' => 'Selecciona una sucursal.',
            'test_mode.required' => 'Selecciona una modalidad de prueba.',
            'event_code.required' => 'Selecciona el evento significativo para la contingencia.',
            'event_description.required' => 'Describe la contingencia de prueba.',
            'sin_cafc_range_id.required' => 'Selecciona el CAFC para los eventos 5, 6 o 7.',
            'document_sector_code.required' => 'Selecciona el tipo de facturación.',
            'document_sector_code.in' => 'El tipo de facturación seleccionado no está habilitado.',
            'economic_activity_code.required' => 'Selecciona una actividad económica.',
            'invoice_count.between' => 'La prueba en línea debe contener entre 1 y 500 facturas.',
            'payment_method_code.not_in' => 'Las pruebas masivas no admiten tarjeta porque requieren un número individual.',
        ];
    }

    private function usesManualCafc(): bool
    {
        return $this->input('test_mode') === InvoiceTestMode::OfflineContingency->value
            && in_array((int) $this->input('event_code'), [5, 6, 7], true);
    }
}
