<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceCustomerNotificationType;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatOperation;
use App\Jobs\SendInvoiceCustomerNotificationJob;
use App\Models\SinCatalogItem;
use App\Models\SinCufd;
use App\Models\SinInvoiceIssue;
use App\Models\SinSiatAttempt;
use App\Models\User;
use App\Services\Billing\Contracts\InvoiceCancellationSiatClient;
use App\Services\Billing\InvoiceCancellationService;
use App\Services\Billing\InvoiceSiatResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InvoiceCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancels_valid_invoice_individually_and_notifies_buyer(): void
    {
        Queue::fake();
        [$invoice, $actor, $cufd] = $this->context();
        SinSiatAttempt::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_invoice_issue_id' => $invoice->id,
            'user_id' => $actor->id,
            'operation' => SiatOperation::ReceiveInvoice,
            'attempt_number' => 1,
            'attempt_status' => SiatAttemptStatus::Succeeded,
        ]);
        $client = new RecordingCancellationClient(new InvoiceSiatResponse([
            'RespuestaServicioFacturacion' => ['codigoEstado' => 905, 'transaccion' => true, 'codigoDescripcion' => 'Anulación confirmada'],
        ], 45));
        $this->app->instance(InvoiceCancellationSiatClient::class, $client);

        $result = app(InvoiceCancellationService::class)->cancel($invoice, (int) $cufd->sin_point_of_sale_id, 1, $actor);
        $repeated = app(InvoiceCancellationService::class)->cancel($result, (int) $cufd->sin_point_of_sale_id, 1, $actor);

        self::assertSame(InvoiceFiscalStatus::CancelledInSiat, $result->fiscal_status);
        self::assertSame(908, $result->status_code);
        self::assertSame(905, $result->cancellation_status_code);
        self::assertNotNull($result->cancelled_at);
        self::assertSame(1, $client->calls);
        self::assertSame(InvoiceFiscalStatus::CancelledInSiat, $repeated->fiscal_status);
        $this->assertDatabaseCount('sin_siat_attempts', 2);
        $this->assertDatabaseHas('sin_siat_attempts', [
            'sin_invoice_issue_id' => $invoice->id,
            'operation' => SiatOperation::CancelInvoice->value,
            'attempt_status' => SiatAttemptStatus::Succeeded->value,
            'attempt_number' => 2,
        ]);
        Queue::assertPushed(SendInvoiceCustomerNotificationJob::class, fn ($job): bool => $job->invoiceId === $invoice->id
            && $job->type === InvoiceCustomerNotificationType::Cancelled);
    }

    public function test_rejects_cancellation_after_day_nine_of_following_month(): void
    {
        [$invoice, $actor, $cufd] = $this->context(['issued_at' => now()->subMonthsNoOverflow(2)]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('plazo de anulación venció');

        app(InvoiceCancellationService::class)->cancel($invoice, (int) $cufd->sin_point_of_sale_id, 1, $actor);
    }

    public function test_cancellation_succeeds_without_buyer_email_and_skips_notification(): void
    {
        Queue::fake();
        [$invoice, $actor, $cufd] = $this->context();
        $invoice->customer->forceFill(['email' => null])->save();
        $client = new RecordingCancellationClient(new InvoiceSiatResponse([
            'RespuestaServicioFacturacion' => ['codigoEstado' => 905, 'transaccion' => true],
        ], 45));
        $this->app->instance(InvoiceCancellationSiatClient::class, $client);

        $result = app(InvoiceCancellationService::class)->cancel($invoice, (int) $cufd->sin_point_of_sale_id, 1, $actor);

        self::assertSame(InvoiceFiscalStatus::CancelledInSiat, $result->fiscal_status);
        Queue::assertNotPushed(SendInvoiceCustomerNotificationJob::class);
    }

    /** @param array<string, mixed> $overrides
     * @return array{SinInvoiceIssue, User, SinCufd}
     */
    private function context(array $overrides = []): array
    {
        $invoice = SinInvoiceIssue::factory()->create(array_merge([
            'fiscal_status' => InvoiceFiscalStatus::Validated,
            'status_code' => 908,
            'status_label' => 'Validada',
            'transaccion' => true,
            'invoice_number' => 10,
        ], $overrides));
        $invoice->customer->forceFill(['email' => 'comprador@example.test'])->save();
        $actor = User::factory()->create(['company_id' => $invoice->company_id]);
        $cufd = $invoice->cufd;
        $cufd->forceFill(['tax_id' => $invoice->tax_id])->save();
        SinCatalogItem::factory()->create([
            'company_id' => $invoice->company_id,
            'catalog_key' => 'motivos_anulacion',
            'classifier_code' => '1',
            'description' => 'Factura emitida con datos incorrectos',
            'is_active' => true,
        ]);

        return [$invoice->refresh(), $actor, $cufd];
    }
}

final class RecordingCancellationClient implements InvoiceCancellationSiatClient
{
    public int $calls = 0;

    public function __construct(private readonly InvoiceSiatResponse $response) {}

    public function cancel(SinInvoiceIssue $invoice, SinCufd $currentCufd, int $reasonCode): InvoiceSiatResponse
    {
        $this->calls++;

        return $this->response;
    }
}
