<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseInvoiceTestBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoice-tests.run') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
