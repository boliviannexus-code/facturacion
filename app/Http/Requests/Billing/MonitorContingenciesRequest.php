<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MonitorContingenciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contingencies.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'branch_id' => ['nullable', 'integer'],
            'point_of_sale_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(InvoiceFiscalStatus::class)],
            'modality' => ['nullable', Rule::enum(InvoiceEmissionMode::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'event_id' => ['nullable', 'integer'],
            'cuf' => ['nullable', 'string', 'max:256'],
            'number' => ['nullable', 'integer', 'min:1'],
            'client' => ['nullable', 'string', 'max:120'],
        ];
    }
}
