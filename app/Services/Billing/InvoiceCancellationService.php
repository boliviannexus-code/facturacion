<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceCommercialStatus;
use App\Enums\InvoiceCustomerNotificationType;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\SaleStatus;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatMessageSeverity;
use App\Enums\SiatOperation;
use App\Jobs\SendInvoiceCustomerNotificationJob;
use App\Models\SinCatalogItem;
use App\Models\SinCufd;
use App\Models\SinFiscalStatusHistory;
use App\Models\SinInvoiceIssue;
use App\Models\SinResponseMessage;
use App\Models\SinSiatAttempt;
use App\Models\User;
use App\Services\Billing\Contracts\InvoiceCancellationSiatClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class InvoiceCancellationService
{
    public function __construct(private InvoiceCancellationSiatClient $client) {}

    public function deadline(SinInvoiceIssue $invoice): CarbonImmutable
    {
        return CarbonImmutable::instance($invoice->issued_at)
            ->startOfMonth()->addMonth()->day(9)->endOfDay();
    }

    public function cancel(SinInvoiceIssue $invoice, int $pointOfSaleId, int $reasonCode, User $actor): SinInvoiceIssue
    {
        [$locked, $cufd, $reason, $attempt] = DB::transaction(function () use ($invoice, $pointOfSaleId, $reasonCode, $actor): array {
            $locked = SinInvoiceIssue::query()->withoutGlobalScope('company')
                ->with(['customer'])->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->company_id !== $actor->company_id) {
                abort(404);
            }

            if ($locked->fiscal_status === InvoiceFiscalStatus::CancelledInSiat) {
                return [$locked, null, null, null];
            }

            if ($locked->reversed_at || $locked->fiscal_status === InvoiceFiscalStatus::ReversedInSiat) {
                throw ValidationException::withMessages(['invoice' => 'Una factura cuya anulación fue revertida no puede volver a anularse.']);
            }

            if (! in_array($locked->fiscal_status, [InvoiceFiscalStatus::Validated, InvoiceFiscalStatus::ValidatedAfterContingency, InvoiceFiscalStatus::ManualValidated], true)
                || ! $locked->transaccion || $locked->status_code !== 908) {
                throw ValidationException::withMessages(['invoice' => 'Solo puede anularse una factura registrada como válida en el SIN.']);
            }

            if (now()->isAfter($this->deadline($locked))) {
                throw ValidationException::withMessages(['invoice' => 'El plazo de anulación venció el '.$this->deadline($locked)->format('d/m/Y').'.']);
            }

            $reason = SinCatalogItem::query()->withoutGlobalScope('company')
                ->where('company_id', $locked->company_id)->where('catalog_key', 'motivos_anulacion')
                ->where('classifier_code', (string) $reasonCode)->active()->first();
            if (! $reason) {
                throw ValidationException::withMessages(['reason_code' => 'Seleccione un motivo de anulación vigente del catálogo SIN.']);
            }

            $cufd = SinCufd::query()->withoutGlobalScope('company')
                ->with(['apiToken', 'authorization', 'cuis'])
                ->where('company_id', $locked->company_id)->where('sin_point_of_sale_id', $pointOfSaleId)
                ->where('tax_id', $locked->tax_id)
                ->where('environment_code', $locked->environment_code->value)
                ->where('modality_code', $locked->modality_code->value)->current()->latest('expires_at')->first();
            if (! $cufd || ! $cufd->cuis?->transaccion) {
                throw ValidationException::withMessages(['point_of_sale_id' => 'El punto de venta no tiene CUFD y CUIS vigentes para realizar la anulación.']);
            }

            $number = (int) $locked->attempts()->max('attempt_number') + 1;
            $attempt = SinSiatAttempt::query()->create([
                'company_id' => $locked->company_id, 'sin_invoice_issue_id' => $locked->id, 'user_id' => $actor->id,
                'idempotency_key' => (string) Str::uuid(), 'operation' => SiatOperation::CancelInvoice,
                'attempt_number' => $number, 'attempt_status' => SiatAttemptStatus::Sending,
                'endpoint' => 'purchase-sale-invoice/cancellation',
                'request_hash' => hash('sha256', $locked->cuf.'|'.$reasonCode),
                'request_payload' => [
                    'cuf' => $locked->cuf,
                    'codigoMotivo' => $reasonCode,
                    'sin_point_of_sale_id' => $pointOfSaleId,
                    'original_fiscal_status' => $locked->fiscal_status->value,
                ],
                'started_at' => now(),
            ]);
            $locked->forceFill([
                'fiscal_status' => InvoiceFiscalStatus::CancellationPending,
                'status_label' => 'Anulación pendiente',
                'cancellation_requested_by_user_id' => $actor->id,
                'cancellation_point_of_sale_id' => $pointOfSaleId,
                'cancellation_reason_code' => $reasonCode,
                'cancellation_reason' => $reason->description,
                'cancellation_requested_at' => now(),
            ])->save();
            SinFiscalStatusHistory::query()->create([
                'company_id' => $locked->company_id,
                'sin_invoice_issue_id' => $locked->id,
                'sin_siat_attempt_id' => $attempt->id,
                'user_id' => $actor->id,
                'from_status' => $attempt->request_payload['original_fiscal_status'],
                'to_status' => InvoiceFiscalStatus::CancellationPending,
                'emission_mode' => $locked->emission_mode,
                'reason_code' => 'CANCELLATION_REQUESTED',
                'reason' => 'Solicitud individual de anulación enviada al SIN.',
                'changed_at' => now(),
            ]);

            return [$locked, $cufd, $reason, $attempt];
        }, 3);

        if (! $attempt) {
            return $locked;
        }

        try {
            $response = $this->client->cancel($locked, $cufd, $reasonCode);
        } catch (InvoiceTransportException $exception) {
            DB::transaction(function () use ($locked, $attempt, $exception): void {
                $originalStatus = InvoiceFiscalStatus::from((string) $attempt->request_payload['original_fiscal_status']);
                $nextStatus = $exception->mayHaveReachedSiat ? InvoiceFiscalStatus::CancellationPending : $originalStatus;
                $attempt->forceFill([
                    'attempt_status' => $exception->mayHaveReachedSiat ? SiatAttemptStatus::Uncertain : SiatAttemptStatus::Failed,
                    'failure_category' => $exception->errorType->failureCategory(), 'message' => $exception->getMessage(), 'finished_at' => now(),
                ])->save();
                $locked->forceFill([
                    'fiscal_status' => $nextStatus,
                    'status_label' => $exception->mayHaveReachedSiat ? 'Anulación por confirmar' : 'Anulación no enviada',
                    'cancellation_message' => $exception->getMessage(),
                ])->save();
                if ($nextStatus !== InvoiceFiscalStatus::CancellationPending) {
                    SinFiscalStatusHistory::query()->create([
                        'company_id' => $locked->company_id,
                        'sin_invoice_issue_id' => $locked->id,
                        'sin_siat_attempt_id' => $attempt->id,
                        'user_id' => $attempt->user_id,
                        'from_status' => InvoiceFiscalStatus::CancellationPending,
                        'to_status' => $nextStatus,
                        'emission_mode' => $locked->emission_mode,
                        'reason_code' => 'CANCELLATION_NOT_SENT',
                        'reason' => $exception->getMessage(),
                        'changed_at' => now(),
                    ]);
                }
            });
            throw ValidationException::withMessages(['invoice' => 'No se confirmó la anulación ante el SIN. Verifique el estado antes de reintentar.']);
        }

        $statusCode = $this->findInt($response->data, 'codigoEstado');
        $transaction = $this->findBool($response->data, 'transaccion') ?? $statusCode === 905;
        $description = $this->findString($response->data, ['codigoDescripcion', 'descripcion', 'mensaje']) ?? ($statusCode === 905 ? 'Anulación confirmada.' : 'Anulación rechazada.');

        $cancelled = DB::transaction(function () use ($locked, $attempt, $response, $statusCode, $transaction, $description): SinInvoiceIssue {
            $invoice = SinInvoiceIssue::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($locked->id);
            $success = $statusCode === 905 && $transaction;
            $originalStatus = InvoiceFiscalStatus::from((string) $attempt->request_payload['original_fiscal_status']);
            $invoice->forceFill([
                'fiscal_status' => $success ? InvoiceFiscalStatus::CancelledInSiat : $originalStatus,
                'commercial_status' => $success ? InvoiceCommercialStatus::Cancelled : $invoice->commercial_status,
                'status_label' => $success ? 'Anulada en el SIN' : 'Anulación rechazada',
                'cancellation_status_code' => $statusCode,
                'cancellation_response' => $response->data,
                'cancellation_message' => $description,
                'duration_ms' => $response->durationMs,
                'cancelled_at' => $success ? now() : null,
            ])->save();
            if ($success && $invoice->sale_id) {
                $invoice->sale()->update(['sale_status' => SaleStatus::Cancelled]);
            }
            $attempt->forceFill([
                'attempt_status' => $success ? SiatAttemptStatus::Succeeded : SiatAttemptStatus::Failed,
                'siat_status_code' => $statusCode, 'duration_ms' => $response->durationMs,
                'message' => $description, 'response' => $response->data, 'finished_at' => now(),
            ])->save();
            SinResponseMessage::query()->create([
                'company_id' => $invoice->company_id,
                'sin_siat_attempt_id' => $attempt->id,
                'message_key' => hash('sha256', ($statusCode ?? '').'|'.$description),
                'service' => SiatOperation::CancelInvoice->value,
                'message_code' => $statusCode !== null ? (string) $statusCode : null,
                'severity' => $success ? SiatMessageSeverity::Info : SiatMessageSeverity::Error,
                'description' => $description,
                'raw_data' => $response->data,
                'received_at' => now(),
            ]);
            SinFiscalStatusHistory::query()->create([
                'company_id' => $invoice->company_id, 'sin_invoice_issue_id' => $invoice->id,
                'sin_siat_attempt_id' => $attempt->id, 'user_id' => $attempt->user_id,
                'from_status' => InvoiceFiscalStatus::CancellationPending, 'to_status' => $invoice->fiscal_status,
                'emission_mode' => $invoice->emission_mode, 'reason_code' => $success ? 'SIN_905' : 'SIN_CANCELLATION_REJECTED',
                'reason' => $description, 'changed_at' => now(),
            ]);

            return $invoice->refresh();
        });

        if ($cancelled->fiscal_status === InvoiceFiscalStatus::CancelledInSiat) {
            $this->notifyBuyer($cancelled);
        }

        return $cancelled->refresh();
    }

    private function notifyBuyer(SinInvoiceIssue $invoice): bool
    {
        if ($invoice->fiscal_status !== InvoiceFiscalStatus::CancelledInSiat || blank($invoice->customer?->email)) {
            return false;
        }

        SendInvoiceCustomerNotificationJob::dispatch(
            (int) $invoice->id,
            InvoiceCustomerNotificationType::Cancelled,
        )->afterCommit();

        return true;
    }

    private function findInt(array $data, string $key): ?int
    {
        $value = $this->find($data, [$key]);

        return is_numeric($value) ? (int) $value : null;
    }

    private function findBool(array $data, string $key): ?bool
    {
        $value = $this->find($data, [$key]);

        return is_bool($value) ? $value : (is_numeric($value) ? (bool) $value : null);
    }

    private function findString(array $data, array $keys): ?string
    {
        $value = $this->find($data, $keys);

        return is_scalar($value) ? trim((string) $value) : null;
    }

    private function find(array $data, array $keys): mixed
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $keys, true)) {
                return $value;
            } if (is_array($value) && ($found = $this->find($value, $keys)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
