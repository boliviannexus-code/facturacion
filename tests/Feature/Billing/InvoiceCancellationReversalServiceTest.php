<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceCustomerNotificationType;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatOperation;
use App\Jobs\SendInvoiceCustomerNotificationJob;
use App\Models\SinCufd;
use App\Models\SinInvoiceIssue;
use App\Models\SinSiatAttempt;
use App\Models\User;
use App\Services\Billing\Contracts\InvoiceCancellationReversalSiatClient;
use App\Services\Billing\InvoiceCancellationReversalService;
use App\Services\Billing\InvoiceSiatResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InvoiceCancellationReversalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reverses_cancellation_once_and_notifies_buyer(): void
    {
        Queue::fake();
        [$invoice, $actor, $cufd] = $this->context();
        foreach ([SiatOperation::ReceiveInvoice, SiatOperation::CancelInvoice] as $index => $operation) {
            SinSiatAttempt::factory()->create(['company_id' => $invoice->company_id, 'sin_invoice_issue_id' => $invoice->id,
                'user_id' => $actor->id, 'operation' => $operation, 'attempt_number' => $index + 1, 'attempt_status' => SiatAttemptStatus::Succeeded]);
        }
        $client = new RecordingReversalClient(new InvoiceSiatResponse(['RespuestaServicioFacturacion' => [
            'codigoEstado' => 907, 'transaccion' => true, 'codigoDescripcion' => 'Reversión Anulada Conforme',
        ]], 38));
        $this->app->instance(InvoiceCancellationReversalSiatClient::class, $client);

        $result = app(InvoiceCancellationReversalService::class)->reverse($invoice, (int) $cufd->sin_point_of_sale_id, $actor);
        $repeated = app(InvoiceCancellationReversalService::class)->reverse($result, (int) $cufd->sin_point_of_sale_id, $actor);

        self::assertSame(InvoiceFiscalStatus::ReversedInSiat, $result->fiscal_status);
        self::assertSame(907, $result->reversal_status_code);
        self::assertNotNull($result->reversed_at);
        self::assertSame(1, $client->calls);
        self::assertSame(InvoiceFiscalStatus::ReversedInSiat, $repeated->fiscal_status);
        $this->assertDatabaseHas('sin_siat_attempts', ['sin_invoice_issue_id' => $invoice->id,
            'operation' => SiatOperation::ReverseCancellation->value, 'attempt_number' => 3, 'attempt_status' => SiatAttemptStatus::Succeeded->value]);
        Queue::assertPushed(SendInvoiceCustomerNotificationJob::class, fn ($job): bool => $job->invoiceId === $invoice->id
            && $job->type === InvoiceCustomerNotificationType::CancellationReversed);
    }

    public function test_rejects_reversal_after_deadline(): void
    {
        [$invoice, $actor, $cufd] = $this->context(['issued_at' => now()->subMonthsNoOverflow(2)]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('plazo de reversión venció');
        app(InvoiceCancellationReversalService::class)->reverse($invoice, (int) $cufd->sin_point_of_sale_id, $actor);
    }

    private function context(array $overrides = []): array
    {
        $invoice = SinInvoiceIssue::factory()->create(array_merge([
            'fiscal_status' => InvoiceFiscalStatus::CancelledInSiat, 'status_code' => 908, 'transaccion' => true,
            'invoice_number' => 10, 'cancellation_status_code' => 905, 'cancelled_at' => now(),
        ], $overrides));
        $invoice->customer->forceFill(['email' => 'comprador@example.test'])->save();
        $actor = User::factory()->create(['company_id' => $invoice->company_id]);
        $cufd = $invoice->cufd;
        $cufd->forceFill(['tax_id' => $invoice->tax_id])->save();

        return [$invoice->refresh(), $actor, $cufd];
    }
}

final class RecordingReversalClient implements InvoiceCancellationReversalSiatClient
{
    public int $calls = 0;

    public function __construct(private readonly InvoiceSiatResponse $response) {}

    public function reverse(SinInvoiceIssue $invoice, SinCufd $currentCufd): InvoiceSiatResponse
    {
        $this->calls++;

        return $this->response;
    }
}
