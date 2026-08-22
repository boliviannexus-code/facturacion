<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\PackageValidationOutcome;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatErrorType;
use App\Enums\SiatMessageSeverity;
use App\Enums\SiatOperation;
use App\Enums\SignificantEventStatus;
use App\Models\SinFiscalStatusHistory;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinInvoicePackageItem;
use App\Models\SinInvoicePackageSequence;
use App\Models\SinResponseMessage;
use App\Models\SinSiatAttempt;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Billing\Packages\Contracts\InvoicePackageSiatClient;
use App\Services\Billing\Packages\DeterministicTarGzipBuilder;
use App\Services\Billing\Packages\PackageInvoiceValidationResult;
use App\Services\Billing\Packages\PackageProcessResult;
use App\Services\Billing\Packages\PackageTransportException;
use App\Services\Billing\Packages\PackageValidationResult;
use App\Services\Siat\SiatLogSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class InvoicePackageService
{
    public function __construct(
        private readonly InvoicePackageSiatClient $client,
        private readonly DeterministicTarGzipBuilder $archiveBuilder,
        private readonly SiatLogSanitizer $sanitizer,
    ) {}

    /** @return EloquentCollection<int, SinInvoicePackage> */
    public function buildForEvent(SinSignificantEvent $significantEvent, ?User $actor = null): EloquentCollection
    {
        return DB::transaction(function () use ($significantEvent, $actor): EloquentCollection {
            $event = SinSignificantEvent::query()
                ->withoutGlobalScope('company')
                ->with(['apiToken', 'authorization', 'branch', 'pointOfSale', 'cuis', 'recoveryCufd', 'creator'])
                ->where('company_id', $significantEvent->company_id)
                ->lockForUpdate()
                ->findOrFail($significantEvent->id);
            $actor = $this->safeActor($event, $actor) ?? $event->creator;

            if (! in_array($event->event_status, [
                SignificantEventStatus::Registered,
                SignificantEventStatus::Packaging,
            ], true)) {
                return $event->packages()->orderBy('package_number')->get();
            }

            $this->ensureEventCanBePackaged($event, $actor);
            $invoices = $this->eligibleInvoices($event);

            if ($invoices->isEmpty()) {
                return $event->packages()->orderBy('package_number')->get();
            }

            $created = new EloquentCollection;

            foreach ($invoices->groupBy(fn (SinInvoiceIssue $invoice): string => $this->scopeKey($invoice)) as $scopeInvoices) {
                foreach ($scopeInvoices->chunk($this->maximumInvoices()) as $invoiceChunk) {
                    $created->push($this->buildPackage($event, $actor, new EloquentCollection($invoiceChunk->all())));
                }
            }

            $event->update([
                'event_status' => SignificantEventStatus::Packaging,
                'status_label' => 'Paquetes generados',
                'updated_by_user_id' => $actor?->id,
                'message' => 'Las facturas fuera de linea fueron agrupadas en paquetes.',
            ]);

            return $created;
        }, 3);
    }

    public function send(SinInvoicePackage $invoicePackage, ?User $actor = null): PackageProcessResult
    {
        $package = $this->packageForCompany($invoicePackage);
        $actor = $this->safePackageActor($package, $actor) ?? $package->creator;

        if (in_array($package->package_status, [
            InvoicePackageStatus::PendingValidation,
            InvoicePackageStatus::Validated,
            InvoicePackageStatus::Observed,
            InvoicePackageStatus::Rejected,
        ], true)) {
            return $this->processResult($package, false, false, 'El paquete ya fue enviado o procesado.');
        }

        if ($package->package_status === InvoicePackageStatus::Sent) {
            return $this->processResult(
                $package,
                true,
                false,
                'El resultado del envio es incierto; el paquete no se reenviara automaticamente.',
            );
        }

        $this->verifyStoredArtifact($package);
        $claim = (string) Str::uuid();
        $attempt = $this->claim($package, $actor, SiatOperation::ReceivePackage, $claim);

        if (! $attempt) {
            $current = $this->packageForCompany($package);

            if ($current->package_status === InvoicePackageStatus::Sent) {
                $this->setEventStatus(
                    $current,
                    SignificantEventStatus::Failed,
                    'El envio del paquete quedo incierto y requiere conciliacion administrativa.',
                );

                return $this->processResult(
                    $current,
                    true,
                    false,
                    'El resultado del envio es incierto; el paquete no se reenviara automaticamente.',
                );
            }

            return $this->processResult(
                $current,
                true,
                false,
                'Otro proceso ya esta enviando el paquete.',
            );
        }

        $this->setEventStatus($package, SignificantEventStatus::Sending, 'Enviando paquetes a SIAT.');

        $archive = Storage::disk('local')->get((string) $package->file_path);

        try {
            $response = $this->client->send($package, $archive);
            $safeResponse = $this->sanitizer->data($response->response) ?? [];
            $safeMessage = $this->sanitizer->text($response->message) ?: 'SIAT no devolvio un mensaje.';

            $package = DB::transaction(function () use (
                $package,
                $actor,
                $attempt,
                $claim,
                $response,
                $safeResponse,
                $safeMessage,
            ): SinInvoicePackage {
                $locked = $this->lockedPackage($package);
                $lockedAttempt = SinSiatAttempt::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($attempt->id);

                if ($locked->send_claim !== $claim) {
                    throw new RuntimeException('El envio perdio su claim de idempotencia.');
                }

                $lockedAttempt->update([
                    'attempt_status' => SiatAttemptStatus::Succeeded,
                    'reception_code' => $response->receptionCode,
                    'siat_status_code' => $response->statusCode,
                    'duration_ms' => $response->durationMs,
                    'message' => $safeMessage,
                    'response' => $safeResponse,
                    'finished_at' => now(),
                ]);
                $this->storeMessages($lockedAttempt, $response->messages, $safeMessage, $response->accepted
                    ? SiatMessageSeverity::Info
                    : SiatMessageSeverity::Error);

                $locked->update([
                    'package_status' => $response->accepted
                        ? InvoicePackageStatus::PendingValidation
                        : InvoicePackageStatus::Rejected,
                    'reception_code' => $response->receptionCode,
                    'siat_status_code' => $response->statusCode,
                    'message' => $safeMessage,
                    'response' => $safeResponse,
                    'sent_by_user_id' => $actor?->id,
                    'sent_at' => now(),
                    'validated_at' => $response->accepted ? null : now(),
                    'send_claim' => null,
                    'send_claimed_at' => null,
                ]);

                if ($response->accepted) {
                    $this->transitionPackageInvoices(
                        $locked,
                        InvoiceFiscalStatus::PackageSent,
                        $lockedAttempt,
                        $safeMessage,
                    );
                    $this->setEventStatus($locked, SignificantEventStatus::Validating, 'Paquetes pendientes de validacion.');
                } else {
                    $this->transitionPackageInvoices(
                        $locked,
                        InvoiceFiscalStatus::Rejected,
                        $lockedAttempt,
                        $safeMessage,
                    );
                }

                return $locked->refresh();
            }, 3);

            if (! $response->accepted) {
                $this->closeEventWhenProcessed($package, $actor);
            }

            return $this->processResult(
                $package,
                $response->accepted,
                false,
                $safeMessage,
            );
        } catch (PackageTransportException $exception) {
            return $this->failSend($package, $attempt, $claim, $exception);
        } catch (Throwable $exception) {
            return $this->failSend($package, $attempt, $claim, new PackageTransportException(
                $exception->getMessage(),
                false,
                SiatErrorType::Unknown,
                $exception,
            ));
        }
    }

    public function checkValidation(SinInvoicePackage $invoicePackage, ?User $actor = null): PackageProcessResult
    {
        $package = $this->packageForCompany($invoicePackage);
        $actor = $this->safePackageActor($package, $actor) ?? $package->creator;

        if (in_array($package->package_status, [
            InvoicePackageStatus::Validated,
            InvoicePackageStatus::Observed,
            InvoicePackageStatus::Rejected,
        ], true)) {
            return $this->processResult($package, false, false, 'El paquete ya tiene un resultado final.');
        }

        if ($package->package_status !== InvoicePackageStatus::PendingValidation || blank($package->reception_code)) {
            return $this->processResult($package, true, false, 'El paquete aun no puede consultarse en SIAT.');
        }

        $claim = (string) Str::uuid();
        $attempt = $this->claim($package, $actor, SiatOperation::ValidatePackage, $claim);

        if (! $attempt) {
            return $this->processResult(
                $this->packageForCompany($package),
                true,
                false,
                'Otro proceso ya esta consultando el paquete.',
            );
        }

        try {
            $response = $this->client->checkValidation($package);
            $package = $this->completeValidation($package, $actor, $attempt, $claim, $response);
            $pending = $response->outcome === PackageValidationOutcome::Pending;

            if (! $pending) {
                $this->closeEventWhenProcessed($package, $actor);
            }

            return $this->processResult($package, $pending, $pending, $response->message);
        } catch (PackageTransportException $exception) {
            return $this->failValidation($package, $attempt, $claim, $exception);
        } catch (Throwable $exception) {
            return $this->failValidation($package, $attempt, $claim, new PackageTransportException(
                $exception->getMessage(),
                false,
                SiatErrorType::Unknown,
                $exception,
            ));
        }
    }

    private function buildPackage(
        SinSignificantEvent $event,
        ?User $actor,
        EloquentCollection $invoices,
    ): SinInvoicePackage {
        if ($invoices->isEmpty() || $invoices->count() > $this->maximumInvoices()) {
            throw new RuntimeException('La cantidad de facturas del paquete es invalida.');
        }

        /** @var SinInvoiceIssue $first */
        $first = $invoices->first();
        $invoices->loadMissing('manualContingency.cafcRange');
        $this->assertSameScope($event, $invoices, $first);
        $cafcCode = $first->emission_mode === InvoiceEmissionMode::ManualCafc
            ? $first->manualContingency?->cafcRange?->cafc_code
            : null;
        $packageKey = (string) Str::uuid();
        $files = [];

        foreach ($invoices as $invoice) {
            $xml = $this->originalXml($invoice);
            $filename = $invoice->cuf.'.xml';

            if (array_key_exists($filename, $files)) {
                throw new RuntimeException('El paquete contiene un CUF duplicado.');
            }

            $files[$filename] = $xml;
        }

        $archive = $this->archiveBuilder->build($files);
        $path = "siat-packages/{$event->company_id}/{$event->id}/{$packageKey}.tar.gz";
        $this->putImmutable($path, $archive);
        $package = SinInvoicePackage::query()->create([
            'company_id' => $event->company_id,
            'sin_api_token_id' => $event->sin_api_token_id,
            'sin_authorization_id' => $event->sin_authorization_id,
            'sin_significant_event_id' => $event->id,
            'sin_branch_id' => $event->sin_branch_id,
            'sin_point_of_sale_id' => $event->sin_point_of_sale_id,
            'sin_cuis_id' => $event->sin_cuis_id,
            'sin_cufd_id' => $event->recovery_sin_cufd_id,
            'created_by_user_id' => $actor?->id,
            'package_key' => $packageKey,
            'package_number' => $this->reservePackageNumber($event),
            'emission_mode' => $first->emission_mode,
            'package_status' => InvoicePackageStatus::Created,
            'invoice_count' => $invoices->count(),
            'cafc_code' => $cafcCode,
            'tax_id' => $first->tax_id,
            'environment_code' => $first->environment_code,
            'modality_code' => $first->modality_code,
            'emission_type_code' => $first->emission_type_code,
            'document_sector_code' => $first->document_sector_code,
            'invoice_document_type_code' => $first->invoice_document_type_code,
            'branch_code' => $first->branch_code,
            'point_of_sale_code' => $first->point_of_sale_code,
        ]);

        foreach ($invoices->values() as $position => $invoice) {
            SinInvoicePackageItem::query()->create([
                'company_id' => $event->company_id,
                'sin_invoice_package_id' => $package->id,
                'sin_invoice_issue_id' => $invoice->id,
                'position' => $position + 1,
                'cuf' => $invoice->cuf,
                'file_hash' => hash('sha256', $files[$invoice->cuf.'.xml']),
            ]);
            $from = $invoice->fiscal_status;
            $invoice->forceFill([
                'fiscal_status' => InvoiceFiscalStatus::Packaged,
                'status_label' => 'Empaquetada',
            ])->save();
            $this->recordTransition(
                $invoice,
                $from,
                InvoiceFiscalStatus::Packaged,
                null,
                'Factura agregada a un paquete inmutable.',
                (int) $package->id,
            );
        }

        $package->update([
            'package_status' => InvoicePackageStatus::PendingSend,
            'file_path' => $path,
            'file_hash' => hash('sha256', $archive),
            'file_size' => strlen($archive),
            'generated_at' => now(),
            'message' => 'Paquete generado y pendiente de envio.',
        ]);

        return $package->refresh();
    }

    private function completeValidation(
        SinInvoicePackage $package,
        ?User $actor,
        SinSiatAttempt $attempt,
        string $claim,
        PackageValidationResult $response,
    ): SinInvoicePackage {
        return DB::transaction(function () use ($package, $actor, $attempt, $claim, $response): SinInvoicePackage {
            $locked = $this->lockedPackage($package);
            $lockedAttempt = SinSiatAttempt::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->validation_claim !== $claim) {
                throw new RuntimeException('La validacion perdio su claim de idempotencia.');
            }

            $safeResponse = $this->sanitizer->data($response->response) ?? [];
            $safeMessage = $this->sanitizer->text($response->message) ?: 'SIAT no devolvio un mensaje.';
            $pending = $response->outcome === PackageValidationOutcome::Pending;
            $lockedAttempt->update([
                'attempt_status' => SiatAttemptStatus::Succeeded,
                'reception_code' => $locked->reception_code,
                'siat_status_code' => $response->statusCode,
                'duration_ms' => $response->durationMs,
                'message' => $safeMessage,
                'response' => $safeResponse,
                'finished_at' => now(),
            ]);
            $severity = match ($response->outcome) {
                PackageValidationOutcome::Validated => SiatMessageSeverity::Info,
                PackageValidationOutcome::Pending,
                PackageValidationOutcome::Observed => SiatMessageSeverity::Warning,
                PackageValidationOutcome::Rejected => SiatMessageSeverity::Error,
            };
            $this->storeMessages($lockedAttempt, $response->messages, $safeMessage, $severity);
            $locked->update([
                'package_status' => $response->outcome->packageStatus(),
                'siat_status_code' => $response->statusCode,
                'message' => $safeMessage,
                'response' => $safeResponse,
                'validated_by_user_id' => $pending ? null : $actor?->id,
                'validated_at' => $pending ? null : now(),
                'last_validation_at' => now(),
                'validation_checks' => $locked->validation_checks + 1,
                'validation_claim' => null,
                'validation_claimed_at' => null,
            ]);

            if (! $pending) {
                $this->applyInvoiceValidationResults($locked, $lockedAttempt, $response);
            }

            return $locked->refresh();
        }, 3);
    }

    private function applyInvoiceValidationResults(
        SinInvoicePackage $package,
        SinSiatAttempt $attempt,
        PackageValidationResult $response,
    ): void {
        $byCuf = collect($response->invoiceResults)->keyBy(
            fn (PackageInvoiceValidationResult $result): string => $result->cuf,
        );
        $package->loadMissing('items.invoice');

        foreach ($package->items as $item) {
            $invoice = SinInvoiceIssue::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($item->sin_invoice_issue_id);
            /** @var PackageInvoiceValidationResult|null $individual */
            $individual = $byCuf->get($item->cuf);
            $outcome = $individual?->outcome ?? $response->outcome;

            if ($outcome === PackageValidationOutcome::Pending) {
                $outcome = $response->outcome;
            }

            $to = $outcome->invoiceStatus();
            if ($to === null) {
                continue;
            }

            $from = $invoice->fiscal_status;
            $message = $this->sanitizer->text($individual?->message ?: $response->message)
                ?: 'SIAT no devolvio un mensaje para la factura.';
            $invoiceResponse = $this->sanitizer->data($individual?->rawData ?: $response->response) ?? [];
            $invoice->forceFill([
                'fiscal_status' => $to,
                'status_code' => $individual?->statusCode ?? $response->statusCode,
                'status_label' => match ($to) {
                    InvoiceFiscalStatus::ValidatedAfterContingency => 'Validada despues de contingencia',
                    InvoiceFiscalStatus::Observed => 'Observada en paquete',
                    default => 'Rechazada en paquete',
                },
                'transaccion' => $to === InvoiceFiscalStatus::ValidatedAfterContingency,
                'message' => $message,
                'response' => $invoiceResponse,
            ])->save();
            $this->syncManualStatus($invoice, $to);
            $this->recordTransition($invoice, $from, $to, $attempt, $message);
        }
    }

    private function failSend(
        SinInvoicePackage $package,
        SinSiatAttempt $attempt,
        string $claim,
        PackageTransportException $exception,
    ): PackageProcessResult {
        $message = $this->sanitizer->text($exception->getMessage()) ?: 'Error enviando el paquete.';
        $retryable = ! $exception->mayHaveReachedSiat && $exception->errorType->isRetryable();
        $package = DB::transaction(function () use ($package, $attempt, $claim, $exception, $message, $retryable): SinInvoicePackage {
            $locked = $this->lockedPackage($package);
            $lockedAttempt = SinSiatAttempt::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->send_claim !== $claim) {
                throw new RuntimeException('El fallo de envio perdio su claim de idempotencia.');
            }

            $lockedAttempt->update([
                'attempt_status' => $exception->mayHaveReachedSiat
                    ? SiatAttemptStatus::Uncertain
                    : SiatAttemptStatus::Failed,
                'failure_category' => $exception->errorType->failureCategory(),
                'message' => $message,
                'finished_at' => now(),
            ]);
            $locked->update([
                'package_status' => $exception->mayHaveReachedSiat
                    ? InvoicePackageStatus::Sent
                    : ($retryable ? InvoicePackageStatus::PendingSend : InvoicePackageStatus::Failed),
                'message' => $exception->mayHaveReachedSiat
                    ? 'El paquete puede haber llegado a SIAT; no se reenviara automaticamente.'
                    : $message,
                'sent_at' => $exception->mayHaveReachedSiat ? now() : null,
                'send_claim' => null,
                'send_claimed_at' => null,
            ]);

            return $locked->refresh();
        }, 3);

        if ($retryable) {
            $this->setEventStatus($package, SignificantEventStatus::Packaging, 'El envio del paquete sera reintentado.');
        } elseif (! $exception->mayHaveReachedSiat) {
            $this->setEventStatus($package, SignificantEventStatus::Failed, 'El paquete requiere revision administrativa.');
        }

        return $this->processResult($package, true, $retryable, (string) $package->message);
    }

    private function failValidation(
        SinInvoicePackage $package,
        SinSiatAttempt $attempt,
        string $claim,
        PackageTransportException $exception,
    ): PackageProcessResult {
        $message = $this->sanitizer->text($exception->getMessage()) ?: 'Error consultando el paquete.';
        $retryable = $exception->errorType->isRetryable();
        $package = DB::transaction(function () use ($package, $attempt, $claim, $exception, $message): SinInvoicePackage {
            $locked = $this->lockedPackage($package);
            $lockedAttempt = SinSiatAttempt::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->validation_claim !== $claim) {
                throw new RuntimeException('El fallo de validacion perdio su claim de idempotencia.');
            }

            $lockedAttempt->update([
                'attempt_status' => SiatAttemptStatus::Failed,
                'failure_category' => $exception->errorType->failureCategory(),
                'message' => $message,
                'finished_at' => now(),
            ]);
            $locked->update([
                'package_status' => InvoicePackageStatus::PendingValidation,
                'message' => $message,
                'last_validation_at' => now(),
                'validation_checks' => $locked->validation_checks + 1,
                'validation_claim' => null,
                'validation_claimed_at' => null,
            ]);

            return $locked->refresh();
        }, 3);

        return $this->processResult($package, true, $retryable, $message);
    }

    private function claim(
        SinInvoicePackage $package,
        ?User $actor,
        SiatOperation $operation,
        string $claim,
    ): ?SinSiatAttempt {
        return DB::transaction(function () use ($package, $actor, $operation, $claim): ?SinSiatAttempt {
            $locked = $this->lockedPackage($package);
            $claimField = $operation === SiatOperation::ReceivePackage ? 'send_claim' : 'validation_claim';
            $claimedAtField = $operation === SiatOperation::ReceivePackage ? 'send_claimed_at' : 'validation_claimed_at';
            $claimedAt = $locked->{$claimedAtField};

            if ($locked->{$claimField} !== null && $claimedAt?->gt(now()->subMinutes($this->claimTtlMinutes()))) {
                return null;
            }

            if ($operation === SiatOperation::ReceivePackage && $locked->{$claimField} !== null) {
                $inFlight = SinSiatAttempt::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $locked->company_id)
                    ->where('sin_invoice_package_id', $locked->id)
                    ->where('operation', SiatOperation::ReceivePackage)
                    ->where('attempt_status', SiatAttemptStatus::Sending)
                    ->latest('attempt_number')
                    ->lockForUpdate()
                    ->first();

                if ($inFlight) {
                    $message = 'El worker termino durante el envio; el resultado ante SIAT es incierto.';
                    $inFlight->update([
                        'attempt_status' => SiatAttemptStatus::Uncertain,
                        'message' => $message,
                        'finished_at' => now(),
                    ]);
                    $locked->update([
                        'package_status' => InvoicePackageStatus::Sent,
                        'message' => $message.' No se reenviara automaticamente.',
                        'sent_at' => $claimedAt ?? now(),
                        'send_claim' => null,
                        'send_claimed_at' => null,
                    ]);

                    return null;
                }
            }

            $attemptNumber = (int) SinSiatAttempt::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $locked->company_id)
                ->where('sin_invoice_package_id', $locked->id)
                ->max('attempt_number') + 1;
            $attempt = SinSiatAttempt::query()->create([
                'company_id' => $locked->company_id,
                'sin_invoice_package_id' => $locked->id,
                'user_id' => $actor?->id,
                'idempotency_key' => (string) Str::uuid(),
                'operation' => $operation,
                'attempt_number' => $attemptNumber,
                'attempt_status' => SiatAttemptStatus::Sending,
                'endpoint' => 'purchase-sale-invoice',
                'request_hash' => $locked->file_hash,
                'request_payload' => [
                    'package_key' => $locked->package_key,
                    'invoice_count' => $locked->invoice_count,
                    'file_hash' => $locked->file_hash,
                    'reception_code' => $operation === SiatOperation::ValidatePackage ? $locked->reception_code : null,
                ],
                'started_at' => now(),
            ]);
            $locked->update([
                $claimField => $claim,
                $claimedAtField => now(),
            ]);

            return $attempt;
        }, 3);
    }

    private function transitionPackageInvoices(
        SinInvoicePackage $package,
        InvoiceFiscalStatus $to,
        SinSiatAttempt $attempt,
        string $message,
    ): void {
        $package->loadMissing('items');

        foreach ($package->items as $item) {
            $invoice = SinInvoiceIssue::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($item->sin_invoice_issue_id);
            $from = $invoice->fiscal_status;

            if ($from === $to) {
                continue;
            }

            $invoice->forceFill([
                'fiscal_status' => $to,
                'status_label' => match ($to) {
                    InvoiceFiscalStatus::PackageSent => 'Paquete enviado',
                    InvoiceFiscalStatus::Rejected => 'Paquete rechazado',
                    default => $invoice->status_label,
                },
                'message' => $message,
            ])->save();
            $this->recordTransition($invoice, $from, $to, $attempt, $message);
        }
    }

    private function closeEventWhenProcessed(SinInvoicePackage $package, ?User $actor): void
    {
        DB::transaction(function () use ($package, $actor): void {
            $event = SinSignificantEvent::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $package->company_id)
                ->lockForUpdate()
                ->findOrFail($package->sin_significant_event_id);
            $hasUnfinishedPackages = SinInvoicePackage::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $package->company_id)
                ->where('sin_significant_event_id', $event->id)
                ->whereNotIn('package_status', [
                    InvoicePackageStatus::Validated,
                    InvoicePackageStatus::Observed,
                    InvoicePackageStatus::Rejected,
                ])
                ->exists();
            $hasUnfinishedInvoices = $this->eventInvoicesQuery($event)
                ->whereIn('emission_mode', [InvoiceEmissionMode::OfflineDigital, InvoiceEmissionMode::ManualCafc])
                ->whereNotIn('fiscal_status', [
                    InvoiceFiscalStatus::ValidatedAfterContingency,
                    InvoiceFiscalStatus::Observed,
                    InvoiceFiscalStatus::Rejected,
                ])
                ->exists();

            if ($hasUnfinishedPackages || $hasUnfinishedInvoices || ! $event->packages()->exists()) {
                return;
            }

            $event->update([
                'event_status' => SignificantEventStatus::Completed,
                'status_label' => 'Contingencia procesada',
                'message' => 'Todas las facturas digitales del evento recibieron un resultado de SIAT.',
                'closed_at' => now(),
                'closed_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
            ]);
        }, 3);
    }

    private function setEventStatus(SinInvoicePackage $package, SignificantEventStatus $status, string $message): void
    {
        SinSignificantEvent::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $package->company_id)
            ->whereKey($package->sin_significant_event_id)
            ->whereNull('closed_at')
            ->update([
                'event_status' => $status,
                'status_label' => $message,
                'updated_at' => now(),
            ]);
    }

    private function eligibleInvoices(SinSignificantEvent $event): EloquentCollection
    {
        return $this->eventInvoicesQuery($event)
            ->whereIn('emission_mode', [InvoiceEmissionMode::OfflineDigital, InvoiceEmissionMode::ManualCafc])
            ->whereIn('fiscal_status', [
                InvoiceFiscalStatus::OfflineIssued,
                InvoiceFiscalStatus::PendingPackage,
            ])
            ->whereNotNull('xml_path')
            ->whereNotNull('cuf')
            ->whereDoesntHave('packageItem')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function eventInvoicesQuery(SinSignificantEvent $event): Builder
    {
        return SinInvoiceIssue::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $event->company_id)
            ->where(function ($query) use ($event): void {
                $query->where('sin_significant_event_id', $event->id)
                    ->when(
                        (int) $event->sin_invoice_issue_id > 0,
                        fn ($query) => $query->orWhere('id', (int) $event->sin_invoice_issue_id),
                    );
            });
    }

    private function ensureEventCanBePackaged(SinSignificantEvent $event, ?User $actor): void
    {
        if (! $actor || ! $event->apiToken || ! $event->authorization || ! $event->branch
            || ! $event->pointOfSale || ! $event->cuis || ! $event->recoveryCufd
            || blank($event->reception_code)) {
            throw ValidationException::withMessages([
                'event' => 'El evento registrado no tiene toda la configuracion fiscal requerida para crear paquetes.',
            ]);
        }

        foreach ([$actor, $event->apiToken, $event->authorization, $event->branch, $event->pointOfSale, $event->cuis, $event->recoveryCufd] as $model) {
            if ((int) $model->company_id !== (int) $event->company_id) {
                throw ValidationException::withMessages([
                    'event' => 'La configuracion del evento contiene datos de otra empresa.',
                ]);
            }
        }
    }

    private function assertSameScope(
        SinSignificantEvent $event,
        EloquentCollection $invoices,
        SinInvoiceIssue $first,
    ): void {
        foreach ($invoices as $invoice) {
            $belongsToEvent = (int) $invoice->sin_significant_event_id === (int) $event->id
                || ((int) $event->sin_invoice_issue_id > 0 && (int) $invoice->id === (int) $event->sin_invoice_issue_id);
            $sameScope = (int) $invoice->company_id === (int) $event->company_id
                && $belongsToEvent
                && (int) $invoice->sin_branch_id === (int) $event->sin_branch_id
                && (int) $invoice->sin_point_of_sale_id === (int) $event->sin_point_of_sale_id
                && (int) $invoice->sin_cuis_id === (int) $event->sin_cuis_id
                && (string) $invoice->tax_id === (string) $first->tax_id
                && (int) $invoice->sin_branch_id === (int) $first->sin_branch_id
                && (int) $invoice->sin_point_of_sale_id === (int) $first->sin_point_of_sale_id
                && $invoice->environment_code === $first->environment_code
                && $invoice->modality_code === $first->modality_code
                && (int) $invoice->emission_type_code === (int) $first->emission_type_code
                && (int) $invoice->document_sector_code === (int) $first->document_sector_code
                && (int) $invoice->invoice_document_type_code === (int) $first->invoice_document_type_code
                && in_array($invoice->emission_mode, [InvoiceEmissionMode::OfflineDigital, InvoiceEmissionMode::ManualCafc], true)
                && ($invoice->emission_mode !== InvoiceEmissionMode::ManualCafc
                    || ($invoice->manualContingency?->cafcRange?->cafc_code !== null
                        && $invoice->manualContingency->cafcRange->cafc_code === $first->manualContingency?->cafcRange?->cafc_code))
                && ! in_array($invoice->fiscal_status, [
                    InvoiceFiscalStatus::Validated,
                    InvoiceFiscalStatus::ValidatedAfterContingency,
                    InvoiceFiscalStatus::ManualValidated,
                ], true);

            if (! $sameScope) {
                throw new RuntimeException('Las facturas no cumplen el alcance fiscal del paquete.');
            }
        }
    }

    private function scopeKey(SinInvoiceIssue $invoice): string
    {
        return implode('|', [
            $invoice->company_id,
            $invoice->sin_significant_event_id,
            $invoice->tax_id,
            $invoice->sin_branch_id,
            $invoice->sin_point_of_sale_id,
            $invoice->sin_cuis_id,
            $invoice->environment_code->value,
            $invoice->modality_code->value,
            $invoice->emission_type_code,
            $invoice->document_sector_code,
            $invoice->invoice_document_type_code,
        ]);
    }

    private function syncManualStatus(SinInvoiceIssue $invoice, InvoiceFiscalStatus $status): void
    {
        if ($invoice->emission_mode !== InvoiceEmissionMode::ManualCafc) {
            return;
        }

        $manualStatus = match ($status) {
            InvoiceFiscalStatus::ValidatedAfterContingency => ManualContingencyInvoiceStatus::Validated,
            InvoiceFiscalStatus::Observed, InvoiceFiscalStatus::Rejected => ManualContingencyInvoiceStatus::Rejected,
            default => null,
        };

        if ($manualStatus !== null) {
            $invoice->manualContingency()->update(['manual_status' => $manualStatus->value]);
        }
    }

    private function originalXml(SinInvoiceIssue $invoice): string
    {
        $disk = Storage::disk('local');

        if (blank($invoice->xml_path) || ! $disk->exists((string) $invoice->xml_path)) {
            throw new RuntimeException("No existe el XML original de la factura {$invoice->id}.");
        }

        $xml = $disk->get((string) $invoice->xml_path);

        if ($xml === '') {
            throw new RuntimeException("El XML original de la factura {$invoice->id} esta vacio.");
        }

        return $xml;
    }

    private function verifyStoredArtifact(SinInvoicePackage $package): void
    {
        $disk = Storage::disk('local');

        if (blank($package->file_path) || blank($package->file_hash) || ! $disk->exists((string) $package->file_path)) {
            throw new RuntimeException('No existe el archivo inmutable del paquete.');
        }

        $contents = $disk->get((string) $package->file_path);

        if (! hash_equals((string) $package->file_hash, hash('sha256', $contents))
            || strlen($contents) !== (int) $package->file_size) {
            throw new RuntimeException('El archivo del paquete no coincide con su hash y tamano registrados.');
        }
    }

    private function putImmutable(string $path, string $contents): void
    {
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            if (! hash_equals(hash('sha256', $disk->get($path)), hash('sha256', $contents))) {
                throw new RuntimeException('Ya existe un archivo fiscal diferente en la ruta del paquete.');
            }

            return;
        }

        if (! $disk->put($path, $contents)) {
            throw new RuntimeException('No se pudo guardar el paquete fiscal.');
        }
    }

    private function reservePackageNumber(SinSignificantEvent $event): int
    {
        $maximum = (int) SinInvoicePackage::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $event->company_id)
            ->where('sin_branch_id', $event->sin_branch_id)
            ->where('sin_point_of_sale_id', $event->sin_point_of_sale_id)
            ->max('package_number');
        DB::table('sin_invoice_package_sequences')->insertOrIgnore([
            'company_id' => $event->company_id,
            'sin_branch_id' => $event->sin_branch_id,
            'sin_point_of_sale_id' => $event->sin_point_of_sale_id,
            'next_number' => $maximum + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sequence = SinInvoicePackageSequence::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $event->company_id)
            ->where('sin_branch_id', $event->sin_branch_id)
            ->where('sin_point_of_sale_id', $event->sin_point_of_sale_id)
            ->lockForUpdate()
            ->firstOrFail();
        $number = $sequence->next_number;
        $sequence->increment('next_number');

        return $number;
    }

    private function recordTransition(
        SinInvoiceIssue $invoice,
        InvoiceFiscalStatus $from,
        InvoiceFiscalStatus $to,
        ?SinSiatAttempt $attempt,
        string $reason,
        ?int $packageId = null,
    ): void {
        if ($from === $to) {
            return;
        }

        SinFiscalStatusHistory::query()->create([
            'company_id' => $invoice->company_id,
            'sin_invoice_issue_id' => $invoice->id,
            'sin_siat_attempt_id' => $attempt?->id,
            'sin_significant_event_id' => $invoice->sin_significant_event_id,
            'sin_invoice_package_id' => $packageId ?? $attempt?->sin_invoice_package_id,
            'user_id' => $invoice->user_id,
            'from_status' => $from,
            'to_status' => $to,
            'emission_mode' => $invoice->emission_mode,
            'reason' => $reason,
            'changed_at' => now(),
        ]);
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function storeMessages(
        SinSiatAttempt $attempt,
        array $messages,
        string $fallback,
        SiatMessageSeverity $severity,
    ): void {
        if ($messages === []) {
            $messages = [['descripcion' => $fallback]];
        }

        foreach ($messages as $position => $row) {
            $safeRow = $this->sanitizer->data($row) ?? [];
            $description = trim((string) ($safeRow['descripcion'] ?? $safeRow['mensaje'] ?? $safeRow['descripcionMensaje'] ?? ''));

            if ($description === '') {
                continue;
            }

            $code = trim((string) ($safeRow['codigo'] ?? $safeRow['codigoMensaje'] ?? ''));
            SinResponseMessage::query()->firstOrCreate([
                'company_id' => $attempt->company_id,
                'sin_siat_attempt_id' => $attempt->id,
                'message_key' => hash('sha256', $position.'|'.$code.'|'.$description),
            ], [
                'service' => $attempt->operation->value,
                'message_code' => $code !== '' ? $code : null,
                'severity' => $severity,
                'description' => $description,
                'raw_data' => $safeRow,
                'received_at' => now(),
            ]);
        }
    }

    private function packageForCompany(SinInvoicePackage $package): SinInvoicePackage
    {
        return SinInvoicePackage::query()
            ->withoutGlobalScope('company')
            ->with(['creator', 'items'])
            ->where('company_id', $package->company_id)
            ->findOrFail($package->id);
    }

    private function lockedPackage(SinInvoicePackage $package): SinInvoicePackage
    {
        return SinInvoicePackage::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $package->company_id)
            ->lockForUpdate()
            ->findOrFail($package->id);
    }

    private function safeActor(SinSignificantEvent $event, ?User $actor): ?User
    {
        return $actor !== null && (int) $actor->company_id === (int) $event->company_id ? $actor : null;
    }

    private function safePackageActor(SinInvoicePackage $package, ?User $actor): ?User
    {
        return $actor !== null && (int) $actor->company_id === (int) $package->company_id ? $actor : null;
    }

    private function maximumInvoices(): int
    {
        return min(500, max(1, (int) config('siat.packages.maximum_invoices', 500)));
    }

    private function claimTtlMinutes(): int
    {
        return max(1, (int) config('siat.packages.claim_ttl_minutes', 5));
    }

    private function processResult(
        SinInvoicePackage $package,
        bool $pending,
        bool $retryable,
        string $message,
    ): PackageProcessResult {
        return new PackageProcessResult($package, $pending, $retryable, $message);
    }
}
