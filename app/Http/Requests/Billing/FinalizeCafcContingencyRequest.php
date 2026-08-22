<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FinalizeCafcContingencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manual-cafc.use') ?? false;
    }

    public function rules(): array
    {
        $companyId = CompanyContext::id($this->user());

        return [
            'event_code' => ['required', 'integer', Rule::in([5, 6, 7]), Rule::exists('sin_catalog_items', 'classifier_code')
                ->where('company_id', $companyId)->where('catalog_key', 'eventos_significativos')->where('is_active', true)],
            'event_description' => ['required', 'string', 'max:500'],
            'event_started_at' => ['required', 'date'],
            'event_ended_at' => ['required', 'date', 'after:event_started_at', 'before_or_equal:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'event_code.in' => 'Contingencias 2 solo admite los eventos significativos 5, 6 y 7.',
            'event_ended_at.after' => 'La fecha final del evento debe ser posterior a su inicio.',
        ];
    }
}
