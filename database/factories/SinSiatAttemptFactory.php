<?php

namespace Database\Factories;

use App\Enums\SiatAttemptStatus;
use App\Enums\SiatOperation;
use App\Models\SinInvoiceIssue;
use App\Models\SinSiatAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SinSiatAttempt> */
class SinSiatAttemptFactory extends Factory
{
    protected $model = SinSiatAttempt::class;

    public function definition(): array
    {
        return [
            'sin_invoice_issue_id' => SinInvoiceIssue::factory(),
            'company_id' => fn (array $a) => SinInvoiceIssue::query()->findOrFail($a['sin_invoice_issue_id'])->company_id,
            'user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'idempotency_key' => (string) Str::uuid(),
            'operation' => SiatOperation::ReceiveInvoice,
            'attempt_number' => 1,
            'attempt_status' => SiatAttemptStatus::Pending,
            'request_hash' => hash('sha256', fake()->uuid()),
            'duration_ms' => 0,
        ];
    }
}
