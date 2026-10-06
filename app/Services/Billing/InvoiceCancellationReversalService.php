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
use App\Models\SinCufd;
use App\Models\SinFiscalStatusHistory;
use App\Models\SinInvoiceIssue;
use App\Models\SinResponseMessage;
use App\Models\SinSiatAttempt;
use App\Models\User;
use App\Services\Billing\Contracts\InvoiceCancellationReversalSiatClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class InvoiceCancellationReversalService
{
    public function __construct(private InvoiceCancellationReversalSiatClient $client) {}

    public function deadline(SinInvoiceIssue $invoice): CarbonImmutable
    {
        return CarbonImmutable::instance($invoice->issued_at)->startOfMonth()->addMonth()->day(9)->endOfDay();
    }

    public function reverse(SinInvoiceIssue $invoice, int $pointOfSaleId, User $actor): SinInvoiceIssue
    {
        [$locked, $cufd, $attempt] = DB::transaction(function () use ($invoice, $pointOfSaleId, $actor): array {
            $locked = SinInvoiceIssue::query()->withoutGlobalScope('company')->with('customer')->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->company_id !== $actor->company_id) {
                abort(404);
            }
            if ($locked->reversed_at || $locked->fiscal_status === InvoiceFiscalStatus::ReversedInSiat) {
                return [$locked, null, null];
            }
            if ($locked->fiscal_status === InvoiceFiscalStatus::ReversalPending) {
                throw ValidationException::withMessages(['invoice' => 'La reversión anterior está por confirmar. Verifique su estado antes de reintentar.']);
            }
            if ($locked->fiscal_status !== InvoiceFiscalStatus::CancelledInSiat || $locked->cancellation_status_code !== 905) {
                throw ValidationException::withMessages(['invoice' => 'Solo puede revertirse una factura anulada correctamente en el SIN.']);
            }
            if (now()->isAfter($this->deadline($locked))) {
                throw ValidationException::withMessages(['invoice' => 'El plazo de reversión venció el '.$this->deadline($locked)->format('d/m/Y').'.']);
            }
            $cufd = SinCufd::query()->withoutGlobalScope('company')->with(['apiToken', 'authorization', 'cuis'])
                ->where('company_id', $locked->company_id)->where('sin_point_of_sale_id', $pointOfSaleId)
                ->where('tax_id', $locked->tax_id)->where('environment_code', $locked->environment_code->value)
                ->where('modality_code', $locked->modality_code->value)->current()->latest('expires_at')->first();
            if (! $cufd || ! $cufd->cuis?->transaccion) {
                throw ValidationException::withMessages(['point_of_sale_id' => 'El punto de venta no tiene CUFD y CUIS vigentes para realizar la reversión.']);
            }
            $attempt = SinSiatAttempt::query()->create([
                'company_id' => $locked->company_id, 'sin_invoice_issue_id' => $locked->id, 'user_id' => $actor->id,
                'idempotency_key' => (string) Str::uuid(), 'operation' => SiatOperation::ReverseCancellation,
                'attempt_number' => (int) $locked->attempts()->max('attempt_number') + 1,
                'attempt_status' => SiatAttemptStatus::Sending, 'endpoint' => 'purchase-sale-invoice/cancellation-reversal',
                'request_hash' => hash('sha256', $locked->cuf.'|REVERSAL'),
                'request_payload' => ['cuf' => $locked->cuf, 'sin_point_of_sale_id' => $pointOfSaleId], 'started_at' => now(),
            ]);
            $locked->forceFill([
                'fiscal_status' => InvoiceFiscalStatus::ReversalPending, 'status_label' => 'Reversión pendiente',
                'reversal_requested_by_user_id' => $actor->id, 'reversal_point_of_sale_id' => $pointOfSaleId,
                'reversal_requested_at' => now(),
            ])->save();
            $this->history($locked, $attempt, InvoiceFiscalStatus::CancelledInSiat, InvoiceFiscalStatus::ReversalPending, 'REVERSAL_REQUESTED', 'Solicitud individual de reversión enviada al SIN.');

            return [$locked, $cufd, $attempt];
        }, 3);
        if (! $attempt) {
            return $locked;
        }

        try {
            $response = $this->client->reverse($locked, $cufd);
        } catch (InvoiceTransportException $exception) {
            DB::transaction(function () use ($locked, $attempt, $exception): void {
                $next = $exception->mayHaveReachedSiat ? InvoiceFiscalStatus::ReversalPending : InvoiceFiscalStatus::CancelledInSiat;
                $attempt->forceFill(['attempt_status' => $exception->mayHaveReachedSiat ? SiatAttemptStatus::Uncertain : SiatAttemptStatus::Failed,
                    'failure_category' => $exception->errorType->failureCategory(), 'message' => $exception->getMessage(), 'finished_at' => now()])->save();
                $locked->forceFill(['fiscal_status' => $next, 'status_label' => $exception->mayHaveReachedSiat ? 'Reversión por confirmar' : 'Anulada en el SIN', 'reversal_message' => $exception->getMessage()])->save();
                if ($next === InvoiceFiscalStatus::CancelledInSiat) {
                    $this->history($locked, $attempt, InvoiceFiscalStatus::ReversalPending, $next, 'REVERSAL_NOT_SENT', $exception->getMessage());
                }
            });
            throw ValidationException::withMessages(['invoice' => 'No se confirmó la reversión ante el SIN. Verifique el estado antes de reintentar.']);
        }

        $code = $this->find($response->data, 'codigoEstado');
        $transaction = $this->find($response->data, 'transaccion');
        $success = (int) $code === 907 && ($transaction === null || $transaction === true || $transaction === 1 || $transaction === 'true');
        $description = (string) ($this->find($response->data, 'codigoDescripcion') ?? $this->find($response->data, 'descripcion') ?? $this->defaultMessage((int) $code));
        $result = DB::transaction(function () use ($locked, $attempt, $response, $code, $success, $description): SinInvoiceIssue {
            $invoice = SinInvoiceIssue::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($locked->id);
            $invoice->forceFill([
                'fiscal_status' => $success ? InvoiceFiscalStatus::ReversedInSiat : InvoiceFiscalStatus::CancelledInSiat,
                'commercial_status' => $success ? InvoiceCommercialStatus::Confirmed : $invoice->commercial_status,
                'status_label' => $success ? 'Válida por reversión en el SIN' : 'Reversión rechazada',
                'reversal_status_code' => is_numeric($code) ? (int) $code : null, 'reversal_response' => $response->data,
                'reversal_message' => $description, 'duration_ms' => $response->durationMs, 'reversed_at' => $success ? now() : null,
            ])->save();
            if ($success && $invoice->sale_id) {
                $invoice->sale()->update(['sale_status' => SaleStatus::Invoiced]);
            }
            $attempt->forceFill(['attempt_status' => $success ? SiatAttemptStatus::Succeeded : SiatAttemptStatus::Failed,
                'siat_status_code' => is_numeric($code) ? (int) $code : null, 'duration_ms' => $response->durationMs,
                'message' => $description, 'response' => $response->data, 'finished_at' => now()])->save();
            SinResponseMessage::query()->create([
                'company_id' => $invoice->company_id, 'sin_siat_attempt_id' => $attempt->id,
                'message_key' => hash('sha256', ($code ?? '').'|'.$description), 'service' => SiatOperation::ReverseCancellation->value,
                'message_code' => $code !== null ? (string) $code : null, 'severity' => $success ? SiatMessageSeverity::Info : SiatMessageSeverity::Error,
                'description' => $description, 'raw_data' => $response->data, 'received_at' => now(),
            ]);
            $this->history($invoice, $attempt, InvoiceFiscalStatus::ReversalPending, $invoice->fiscal_status, $success ? 'SIN_907' : 'SIN_REVERSAL_REJECTED', $description);

            return $invoice->refresh();
        });
        if ($success) {
            $this->notifyBuyer($result);
        }

        return $result->refresh();
    }

    private function notifyBuyer(SinInvoiceIssue $invoice): bool
    {
        if ($invoice->fiscal_status !== InvoiceFiscalStatus::ReversedInSiat || blank($invoice->customer?->email)) {
            return false;
        }
        SendInvoiceCustomerNotificationJob::dispatch(
            (int) $invoice->id,
            InvoiceCustomerNotificationType::CancellationReversed,
        )->afterCommit();

        return true;
    }

    private function history(SinInvoiceIssue $invoice, SinSiatAttempt $attempt, InvoiceFiscalStatus $from, InvoiceFiscalStatus $to, string $code, string $reason): void
    {
        SinFiscalStatusHistory::query()->create(['company_id' => $invoice->company_id, 'sin_invoice_issue_id' => $invoice->id,
            'sin_siat_attempt_id' => $attempt->id, 'user_id' => $attempt->user_id, 'from_status' => $from, 'to_status' => $to,
            'emission_mode' => $invoice->emission_mode, 'reason_code' => $code, 'reason' => $reason, 'changed_at' => now()]);
    }

    private function find(array $data, string $key): mixed
    {
        foreach ($data as $name => $value) {
            if ((string) $name === $key) {
                return $value;
            }
            if (is_array($value) && ($found = $this->find($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private function defaultMessage(int $code): string
    {
        return match ($code) {
            907 => 'Reversión de anulación conforme.', 981 => 'Factura no disponible para reversión.',
            924 => 'La factura no existe en la base de datos del SIN.',
            3011 => 'El sistema no superó las pruebas de autorización para utilizar la reversión.',
            3012 => 'Solicitud de reversión fuera de plazo.', default => 'El SIN rechazó la reversión.',
        };
    }
}
