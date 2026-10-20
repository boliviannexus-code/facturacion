<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CancelInvoiceTestBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoice-tests.run') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'integer', Rule::exists('sin_catalog_items', 'classifier_code')
                ->where('company_id', CompanyContext::id($this->user()))
                ->where('catalog_key', 'motivos_anulacion')->where('is_active', true)],
        ];
    }
}
