<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceCustomerNotificationType;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatOperation;
use App\Enums\SignificantEventStatus;
use App\Models\SinFiscalStatusHistory;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinSiatAttempt;
use App\Models\User;
use App\Jobs\SendInvoiceCustomerNotificationJob;
use App\Services\Billing\Contracts\InvoiceSiatClient;
use App\Services\Siat\SiatLogSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ManualCafcInvoiceSender
{
    public function __construct(
        private readonly InvoiceSiatClient $client,
        private readonly SiatLogSanitizer $sanitizer,
    ) {}

    public function send(SinManualContingencyInvoice $manual, ?User $actor = null): SinManualContingencyInvoice
    {
        [$locked, $attempt] = DB::transaction(function () use ($manual, $actor): array {
            $locked = SinManualContingencyInvoice::query()->withoutGlobalScope('company')
                ->with(['invoice', 'significantEvent'])->lockForUpdate()->findOrFail($manual->id);

            if (in_array($locked->manual_status, [ManualContingencyInvoiceStatus::Validated, ManualContingencyInvoiceStatus::Rejected], true)) {
                return [$locked, null];
            }
            if (! $locked->invoice || ! $locked->invoice->gzip_path) {
                throw ValidationException::withMessages(['manual_invoice_number' => 'La factura debe estar transcrita antes del envío.']);
            }
            if (! $locked->significantEvent || ! in_array($locked->significantEvent->event_status, [
                SignificantEventStatus::Registered, SignificantEventStatus::Packaging,
                SignificantEventStatus::Sending, SignificantEventStatus::Validating,
                SignificantEventStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages(['significant_event_id' => 'El evento significativo aún no fue registrado ante el SIAT.']);
            }

            $existing = $locked->invoice->attempts()->whereIn('attempt_status', [SiatAttemptStatus::Sending, SiatAttemptStatus::Succeeded, SiatAttemptStatus::Uncertain])->latest('attempt_number')->first();
            if ($existing) {
                return [$locked, null];
            }

            $attemptNumber = (int) $locked->invoice->attempts()->max('attempt_number') + 1;
            $attempt = SinSiatAttempt::query()->withoutGlobalScope('company')->create([
                'company_id' => $locked->company_id,
                'sin_invoice_issue_id' => $locked->sin_invoice_issue_id,
                'user_id' => $actor?->id ?? $locked->transcribed_by_user_id,
                'idempotency_key' => (string) Str::uuid(),
                'operation' => SiatOperation::ReceiveInvoice,
                'attempt_number' => $attemptNumber,
                'attempt_status' => SiatAttemptStatus::Sending,
                'endpoint' => 'manual-cafc-invoice',
                'request_hash' => $locked->invoice->hash_file,
                'request_payload' => ['cuf' => $locked->invoice->cuf, 'hash' => $locked->invoice->hash_file],
                'started_at' => now(),
            ]);

            return [$locked, $attempt];
        }, 3);

        if (! $attempt) {
            return $locked;
        }

        try {
            $response = $this->client->send($locked->invoice, Storage::disk('local')->get($locked->invoice->gzip_path));
            $safeResponse = $this->sanitizer->data($response->data, $this->apiToken($locked)) ?? [];

            $result = DB::transaction(function () use ($locked, $attempt, $response, $safeResponse): SinManualContingencyInvoice {
                $manual = SinManualContingencyInvoice::query()->withoutGlobalScope('company')->with('invoice')->lockForUpdate()->findOrFail($locked->id);
                $attempt = SinSiatAttempt::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($attempt->id);
                $statusCode = $this->findInt($safeResponse, 'codigoEstado');
                $transaction = $this->findBoolean($safeResponse, 'transaccion') ?? $statusCode === 908;
                $validated = $transaction && $statusCode === 908;
                $to = $validated ? InvoiceFiscalStatus::ManualValidated : InvoiceFiscalStatus::Rejected;
                $message = $validated ? 'Factura manual validada por el SIN.' : 'La factura manual fue rechazada por el SIN.';
                $reception = $this->findString($safeResponse, 'codigoRecepcion');

                $manual->invoice->forceFill([
                    'invoice_number' => $validated ? $manual->manual_invoice_number : null,
                    'fiscal_status' => $to, 'reception_code' => $reception,
                    'status_code' => $statusCode, 'status_label' => $validated ? 'Manual validada' : 'Manual rechazada',
                    'transaccion' => $transaction, 'response' => $safeResponse,
                    'message' => $message, 'duration_ms' => $response->durationMs, 'sent_at' => now(),
                ])->save();
                $manual->forceFill(['manual_status' => $validated ? ManualContingencyInvoiceStatus::Validated : ManualContingencyInvoiceStatus::Rejected])->save();
                $attempt->forceFill([
                    'attempt_status' => SiatAttemptStatus::Succeeded, 'reception_code' => $reception,
                    'siat_status_code' => $statusCode, 'duration_ms' => $response->durationMs,
                    'message' => $message, 'response' => $safeResponse, 'finished_at' => now(),
                ])->save();
                SinFiscalStatusHistory::query()->create([
                    'company_id' => $manual->company_id, 'sin_invoice_issue_id' => $manual->sin_invoice_issue_id,
                    'sin_siat_attempt_id' => $attempt->id, 'sin_significant_event_id' => $manual->sin_significant_event_id,
                    'user_id' => $manual->transcribed_by_user_id, 'from_status' => InvoiceFiscalStatus::ManualPendingSend,
                    'to_status' => $to, 'emission_mode' => InvoiceEmissionMode::ManualCafc,
                    'reason_code' => $validated ? 'MANUAL_CAFC_VALIDATED' : 'MANUAL_CAFC_REJECTED',
                    'reason' => $message, 'changed_at' => now(),
                ]);

                return $manual->refresh()->load('invoice.customer');
            }, 3);

            if ($result->manual_status === ManualContingencyInvoiceStatus::Validated
                && filled($result->invoice?->customer?->email)) {
                SendInvoiceCustomerNotificationJob::dispatch(
                    (int) $result->sin_invoice_issue_id,
                    InvoiceCustomerNotificationType::Issued,
                )->afterCommit();
            }

            return $result;
        } catch (Throwable $exception) {
            $safeMessage = $this->sanitizer->text($exception->getMessage(), $this->apiToken($locked))
                ?: 'Error no identificado enviando la factura manual.';
            DB::transaction(function () use ($attempt, $exception, $safeMessage): void {
                SinSiatAttempt::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($attempt->id)->forceFill([
                    'attempt_status' => $exception instanceof InvoiceTransportException && $exception->mayHaveReachedSiat
                        ? SiatAttemptStatus::Uncertain : SiatAttemptStatus::Failed,
                    'message' => Str::limit($safeMessage, 1000), 'finished_at' => now(),
                ])->save();
            });
            throw $exception;
        }
    }

    private function apiToken(SinManualContingencyInvoice $manual): string
    {
        return (string) $manual->invoice?->apiToken()->first()?->api_token;
    }

    private function findInt(array $data, string $key): ?int
    {
        $value = $this->find($data, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function findBoolean(array $data, string $key): ?bool
    {
        $value = $this->find($data, $key);

        return is_bool($value) ? $value : (is_numeric($value) ? (bool) $value : null);
    }

    private function findString(array $data, string $key): ?string
    {
        $value = $this->find($data, $key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function find(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        foreach ($data as $value) {
            if (is_array($value) && ($found = $this->find($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
