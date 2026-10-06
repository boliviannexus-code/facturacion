<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class CancelInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.cancel') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'point_of_sale_id' => ['required', 'integer'],
            'reason_code' => ['required', 'integer'],
        ];
    }
}
