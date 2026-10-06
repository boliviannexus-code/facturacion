<?php

namespace Database\Factories;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Models\SinFiscalStatusHistory;
use App\Models\SinInvoiceIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinFiscalStatusHistory> */
class SinFiscalStatusHistoryFactory extends Factory
{
    protected $model = SinFiscalStatusHistory::class;

    public function definition(): array
    {
        return [
            'sin_invoice_issue_id' => SinInvoiceIssue::factory(),
            'company_id' => fn (array $a) => SinInvoiceIssue::query()->findOrFail($a['sin_invoice_issue_id'])->company_id,
            'user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'from_status' => InvoiceFiscalStatus::NotIssued,
            'to_status' => InvoiceFiscalStatus::PendingOnlineSend,
            'emission_mode' => InvoiceEmissionMode::Online,
            'reason' => 'Transicion de prueba.',
            'changed_at' => now(),
        ];
    }
}
