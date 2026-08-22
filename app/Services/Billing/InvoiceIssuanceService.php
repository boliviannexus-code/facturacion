<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\InvoiceCommercialStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoiceIssuanceDecision;
use App\Enums\SaleStatus;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SiatErrorType;
use App\Enums\SiatMessageSeverity;
use App\Enums\SiatModality;
use App\Enums\SiatOperation;
use App\Enums\SignificantEventStatus;
use App\Jobs\SynchronizeOfflineInvoiceJob;
use App\Models\Sale;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinFiscalStatusHistory;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoiceSequence;
use App\Models\SinPointOfSale;
use App\Models\SinResponseMessage;
use App\Models\SinSiatAttempt;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Billing\Contracts\InvoiceSiatClient;
use App\Services\Billing\Contracts\InvoiceXmlSigner;
use App\Services\Siat\InvoiceXmlValidator;
use App\Services\Siat\PurchaseSaleInvoiceXmlBuilder;
use App\Services\Siat\SiatCommunicationService;
use App\Services\Siat\SiatCufGenerator;
use App\Services\Siat\SiatDateTime;
use App\Services\Siat\SiatHealthCheckResult;
use App\Services\Siat\SiatLogSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class InvoiceIssuanceService
{
    private const EMISSION_ONLINE = 1;

    private const EMISSION_OFFLINE = 2;

    public function __construct(
        private readonly SiatCommunicationService $communication,
        private readonly InvoiceSiatClient $siatClient,
        private readonly PurchaseSaleInvoiceXmlBuilder $xmlBuilder,
        private readonly InvoiceXmlValidator $xmlValidator,
        private readonly InvoiceXmlSigner $xmlSigner,
        private readonly SiatCufGenerator $cufGenerator,
        private readonly PurchaseSaleInvoicePdfService $pdf,
        private readonly SaleCommercialEffectService $commercialEffects,
        private readonly SiatLogSanitizer $logSanitizer,
        private readonly InvoiceTotalsCalculator $totals,
        private readonly PaymentMethodPolicy $paymentMethods,
    ) {}

    public function issue(Sale $sale, bool $allowContingency = true): InvoiceIssuanceResult
    {
        $sale = Sale::query()->withoutGlobalScope('company')
            ->with(['company', 'user', 'customer', 'pointOfSale.branch', 'items'])
            ->findOrFail($sale->getKey());

        if ($existing = $this->existingInvoice($sale)) {
            return $this->existingResult($existing);
        }

        $configuration = $this->configuration($sale);

        if (is_string($configuration)) {
            return $this->blocked($configuration);
        }

        [$token, $authorization, $cuis, $cufd] = $configuration;
        $health = $authorization->force_offline_emission
            ? $this->forcedOfflineHealth()
            : $this->communication->verify($token, $sale->pointOfSale, $sale->user);

        $continueOfflineContingency = ($health->ok ?? false) === true
            && $this->hasPendingOfflineInvoices($sale);
        $decision = $continueOfflineContingency
            ? InvoiceIssuanceDecision::OfflineDigital
            : $this->decision($sale, $health, $cufd);

        if (! $allowContingency && $decision !== InvoiceIssuanceDecision::Online) {
            return $this->blocked(
                'La prueba masiva requiere comunicación en línea con el SIAT y no generará facturas de contingencia.',
            );
        }

        if ($decision !== InvoiceIssuanceDecision::Online && $decision !== InvoiceIssuanceDecision::OfflineDigital) {
            return new InvoiceIssuanceResult(
                decision: $decision,
                invoice: null,
                message: $decision === InvoiceIssuanceDecision::ManualCafcRequired
                    ? 'No existe un CUFD utilizable. La venta requiere emision manual con CAFC.'
                    : 'La emision esta bloqueada: '.($health->userMessage ?? 'la falla detectada no habilita contingencia.'),
            );
        }

        if (! $cufd) {
            return $this->blocked('No existe un CUFD utilizable para generar la factura.');
        }

        if ($decision === InvoiceIssuanceDecision::OfflineDigital) {
            // Todas las facturas de una misma contingencia deben conservar el CUFD
            // con el que comenzó el evento, aunque entretanto exista otro CUFD local.
            $cufd = $this->eventCufd($sale) ?? $cufd;
        }

        try {
            $invoice = $this->prepareInvoice(
                $sale,
                $token,
                $authorization,
                $cuis,
                $cufd,
                $decision,
                $health instanceof SiatHealthCheckResult ? $health->errorType : SiatErrorType::Unknown,
            );
        } catch (ValidationException|RuntimeException $exception) {
            return $this->blocked($exception->getMessage());
        }

        if ($decision === InvoiceIssuanceDecision::OfflineDigital) {
            SynchronizeOfflineInvoiceJob::dispatch((int) $sale->company_id, (int) $invoice->id);

            return new InvoiceIssuanceResult(
                decision: $decision,
                invoice: $invoice->refresh(),
                message: $continueOfflineContingency
                    ? 'Factura emitida fuera de linea y agregada a la contingencia pendiente.'
                    : 'Factura emitida fuera de linea y agregada a la cola de sincronizacion.',
            );
        }

        return $this->sendOnline($invoice);
    }

    /**
     * Emite una factura de contingencia simulada únicamente para el laboratorio SIAT.
     * No consulta el estado de comunicación y nunca debe usarse desde una venta normal.
     */
    public function issueOfflineTest(Sale $sale): InvoiceIssuanceResult
    {
        $sale = Sale::query()->withoutGlobalScope('company')
            ->with(['company', 'user', 'customer', 'pointOfSale.branch', 'items'])
            ->findOrFail($sale->getKey());

        if ($existing = $this->existingInvoice($sale)) {
            return $this->existingResult($existing);
        }

        if (! $sale->user?->can('invoice-tests.run')) {
            return $this->blocked('El usuario no tiene permiso para forzar una contingencia de laboratorio.');
        }

        $configuration = $this->configuration($sale);
        if (is_string($configuration)) {
            return $this->blocked($configuration);
        }

        [$token, $authorization, $cuis, $cufd] = $configuration;
        if ($authorization->environment_code !== SiatEnvironment::TestingAndPilot) {
            return $this->blocked('La contingencia forzada solo está permitida en el ambiente Piloto del SIN.');
        }
        if (! $cufd) {
            return $this->blocked('No existe un CUFD utilizable para la prueba de contingencia.');
        }

        try {
            $invoice = $this->prepareInvoice(
                $sale,
                $token,
                $authorization,
                $cuis,
                $cufd,
                InvoiceIssuanceDecision::OfflineDigital,
                SiatErrorType::SiatUnavailable,
                testForced: true,
            );
        } catch (ValidationException|RuntimeException $exception) {
            return $this->blocked($exception->getMessage());
        }

        return new InvoiceIssuanceResult(
            decision: InvoiceIssuanceDecision::OfflineDigital,
            invoice: $invoice->refresh(),
            message: 'Factura de laboratorio emitida fuera de línea.',
        );
    }

    /**
     * Reenvía únicamente una factura cuyo primer envío está confirmado como no entregado al SIN.
     * Conserva el XML, CUF y número fiscal ya asignados.
     */
    public function resendPendingOnline(SinInvoiceIssue $invoice, User $actor): InvoiceIssuanceResult
    {
        $invoice = SinInvoiceIssue::query()->withoutGlobalScope('company')->findOrFail($invoice->id);

        if ((int) $invoice->company_id !== (int) $actor->company_id) {
            abort(404);
        }

        if ($invoice->fiscal_status !== InvoiceFiscalStatus::PendingOnlineSend
            || $invoice->emission_mode !== InvoiceEmissionMode::Online) {
            throw ValidationException::withMessages([
                'invoice' => 'Solo puede reenviarse una factura en estado pendiente de envío al SIN.',
            ]);
        }

        if (! $invoice->gzip_path || ! Storage::disk('local')->exists($invoice->gzip_path)) {
            throw ValidationException::withMessages([
                'invoice' => 'No se encontró el XML fiscal original para reenviar la factura.',
            ]);
        }

        return $this->sendOnline($invoice, true);
    }

    /** @param array<string, mixed> $correction */
    public function correctPaymentAndResend(SinInvoiceIssue $invoice, int $paymentMethodCode, ?string $cardNumber, User $actor, array $correction = []): InvoiceIssuanceResult
    {
        $invoice = SinInvoiceIssue::query()->withoutGlobalScope('company')
            ->with(['sale.company', 'sale.user', 'sale.customer', 'sale.pointOfSale.branch', 'sale.items', 'authorization', 'cufd'])
            ->findOrFail($invoice->id);
        if ((int) $invoice->company_id !== (int) $actor->company_id) {
            abort(404);
        }
        if (! in_array($invoice->fiscal_status, [InvoiceFiscalStatus::Observed, InvoiceFiscalStatus::Rejected], true)
            || ! in_array($invoice->status_code, [904, 902], true)) {
            throw ValidationException::withMessages(['invoice' => 'Solo puede corregirse y reenviarse una factura observada (904) o rechazada (902).']);
        }
        $paymentMethod = SinCatalogItem::query()->withoutGlobalScope('company')
            ->where('company_id', $invoice->company_id)->where('catalog_key', 'tipos_metodo_pago')
            ->where('classifier_code', (string) $paymentMethodCode)->active()->first();
        if (! $paymentMethod) {
            throw ValidationException::withMessages(['payment_method_code' => 'El método de pago no está vigente en el catálogo del SIN.']);
        }
        $maskedCard = $paymentMethodCode === 2 ? $this->maskCardNumber((string) $cardNumber) : null;
        $sale = $invoice->sale;
        if (! $sale || ! $invoice->authorization || ! $invoice->cufd) {
            throw ValidationException::withMessages(['invoice' => 'La factura no conserva la configuración necesaria para regenerar el XML.']);
        }

        if (isset($correction['items'])) {
            $submitted = collect($correction['items'])->keyBy(fn (array $item): int => (int) $item['id']);
            if ($submitted->keys()->sort()->values()->all() !== $sale->items->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['items' => 'El detalle corregido no coincide con los ítems originales de la factura.']);
            }
            $calculation = $this->totals->calculate(
                $submitted->values()->all(), $correction['total_discount'] ?? 0, (string) ($correction['additional_discount_type'] ?? 'FIXED'),
                $correction['additional_discount_percentage'] ?? null, (int) $sale->currency_code,
                $correction['exchange_rate'] ?? 1, $correction['gift_card_amount'] ?? 0, (int) $sale->document_sector_code,
                $this->paymentMethods->catalogItemIsGiftCard($paymentMethod),
            );
            foreach ($submitted->values() as $index => $itemData) {
                $amounts = $calculation['items'][$index];
                $sale->items->firstWhere('id', (int) $itemData['id'])?->forceFill([
                    'quantity' => $amounts['quantity'], 'unit_price' => $amounts['unit_price'],
                    'discount_type' => $amounts['discount_type'],
                    'discount_percentage' => $amounts['discount_type'] === 'PERCENTAGE' ? $amounts['discount_percentage'] : null,
                    'discount_amount' => $amounts['discount_amount'], 'subtotal_amount' => $amounts['subtotal'],
                ])->save();
            }
            $sale->forceFill([
                'subtotal_amount' => $calculation['subtotal_sum'], 'discount_amount' => $calculation['discount_additional'],
                'additional_discount_type' => $correction['additional_discount_type'] ?? 'FIXED',
                'additional_discount_percentage' => ($correction['additional_discount_type'] ?? 'FIXED') === 'PERCENTAGE' ? $correction['additional_discount_percentage'] : null,
                'total_amount' => $calculation['total_amount'], 'exchange_rate' => $calculation['exchange_rate'],
                'gift_card_amount' => $calculation['gift_card_amount'], 'total_amount_currency' => $calculation['total_amount_currency'],
                'total_amount_subject_to_vat' => $calculation['total_amount_subject_to_vat'],
            ]);
        }
        $sale->forceFill(['payment_method_code' => $paymentMethodCode, 'masked_card_number' => $maskedCard])->save();
        $sale->refresh()->load(['company', 'user', 'customer', 'pointOfSale.branch', 'items']);
        $payload = $this->invoicePayload($sale, $invoice->authorization, $invoice->cufd, (int) $invoice->invoice_number, (string) $invoice->cuf);
        $documentSectorCode = (int) $sale->document_sector_code;
        $xml = $this->xmlSigner->sign($this->xmlBuilder->build($payload, $documentSectorCode), $sale);
        $this->xmlValidator->validate($xml, $documentSectorCode);
        $gzip = gzencode($xml, 9);
        if ($gzip === false) {
            throw new RuntimeException('No se pudo comprimir el XML fiscal corregido.');
        }
        $attemptNumber = (int) $invoice->attempts()->max('attempt_number') + 1;
        $base = 'invoices/'.$invoice->company_id.'/'.$invoice->issued_at->format('Y/m').'/'.$invoice->cuf.'-retry-'.$attemptNumber;
        Storage::disk('local')->put($base.'.xml', $xml);
        Storage::disk('local')->put($base.'.xml.gz', $gzip);
        $from = $invoice->fiscal_status;
        $invoice->forceFill([
            'fiscal_status' => InvoiceFiscalStatus::PendingOnlineSend, 'status_label' => 'Corregida, pendiente de reenvío',
            'response' => null, 'message' => null, 'transaccion' => false,
        ])->save();
        $this->recordTransition($invoice, $from, InvoiceFiscalStatus::PendingOnlineSend, null, 'Método de pago corregido; XML regenerado y validado antes del reenvío.');

        return $this->sendOnline($invoice->refresh(), true, $gzip, [
            'xml_path' => $base.'.xml', 'gzip_path' => $base.'.xml.gz',
            'hash' => hash('sha256', $gzip), 'payment_method_code' => $paymentMethodCode,
            'masked_card_number' => $maskedCard,
        ]);
    }

    /**
     * @return array{SinApiToken, SinAuthorization, SinCuis, SinCufd|null}|string
     */
    private function configuration(Sale $sale): array|string
    {
        if ($sale->sale_status !== SaleStatus::Confirmed) {
            return 'La venta no esta confirmada o ya fue procesada.';
        }

        $documentSectorCode = (int) $sale->document_sector_code;
        if (! InvoiceDocumentSector::supports($documentSectorCode)) {
            return 'El documento sector de la venta no está implementado para emisión.';
        }

        if ($documentSectorCode === InvoiceDocumentSector::ZERO_RATE) {
            $allowedActivities = SinCatalogItem::query()->withoutGlobalScope('company')
                ->where('company_id', $sale->company_id)
                ->where('catalog_key', 'actividades_documento_sector')
                ->active()
                ->get(['classifier_code', 'raw_data'])
                ->filter(fn (SinCatalogItem $item): bool => (int) data_get($item->raw_data, 'codigoDocumentoSector') === InvoiceDocumentSector::ZERO_RATE)
                ->map(fn (SinCatalogItem $item): string => (string) data_get($item->raw_data, 'codigoActividad', $item->classifier_code))
                ->all();
            $invalidActivities = $sale->items->pluck('economic_activity_code')->map(static fn ($code): string => (string) $code)
                ->diff($allowedActivities);

            if ($allowedActivities === [] || $invalidActivities->isNotEmpty()) {
                return 'Los productos de Tasa Cero deben pertenecer a una actividad habilitada para el documento sector 8. Sincronice Actividades por Documento Sector y revise la homologación.';
            }

            if (bccomp((string) $sale->total_amount_subject_to_vat, '0', 2) !== 0) {
                return 'La Factura Tasa Cero debe registrar monto total sujeto a IVA igual a cero.';
            }
        }

        if (! $sale->company?->is_active || ! $sale->pointOfSale?->is_active || ! $sale->pointOfSale?->branch?->is_active) {
            return 'La empresa, sucursal o punto de venta no esta activo.';
        }

        if ((int) $sale->customer?->company_id !== (int) $sale->company_id || $sale->items->isEmpty()) {
            return 'La venta no tiene cliente o detalle valido para la empresa.';
        }

        $validPaymentMethod = SinCatalogItem::query()->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('catalog_key', 'tipos_metodo_pago')
            ->where('classifier_code', (string) $sale->payment_method_code)
            ->active()
            ->exists();
        if (! $validPaymentMethod) {
            return 'El método de pago no existe o ya no está vigente en el catálogo del SIN.';
        }
        if ($sale->payment_method_code === 2 && ! preg_match('/^\d{4}0{8}\d{4}$/', (string) $sale->masked_card_number)) {
            return 'El número de tarjeta no está correctamente ofuscado para el método de pago Tarjeta.';
        }
        if ($sale->payment_method_code !== 2 && $sale->masked_card_number !== null) {
            return 'El número de tarjeta solo puede enviarse con el método de pago Tarjeta.';
        }

        $token = SinApiToken::query()->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->first();
        $authorization = SinAuthorization::query()->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->first();

        if (! $token || ! $authorization || $authorization->modality_code !== SiatModality::ComputerizedOnline) {
            return 'Falta token o autorizacion para Facturacion Computarizada en Linea.';
        }

        $cuis = SinCuis::query()->withoutGlobalScope('company')
            ->usable()
            ->where('company_id', $sale->company_id)
            ->where('sin_point_of_sale_id', $sale->sin_point_of_sale_id)
            ->latest('requested_at')
            ->first();

        if (! $cuis) {
            return 'No existe un CUIS vigente para el punto de venta.';
        }

        $cufd = SinCufd::query()->withoutGlobalScope('company')
            ->usable()
            ->where('company_id', $sale->company_id)
            ->where('sin_point_of_sale_id', $sale->sin_point_of_sale_id)
            ->where('expires_at', '>', $sale->issued_at)
            ->latest('requested_at')
            ->first();

        return [$token, $authorization, $cuis, $cufd];
    }

    private function decision(Sale $sale, object $health, ?SinCufd $cufd): InvoiceIssuanceDecision
    {
        if (($health->ok ?? false) === true) {
            return $cufd ? InvoiceIssuanceDecision::Online : InvoiceIssuanceDecision::Blocked;
        }

        $allowsContingency = $health instanceof SiatHealthCheckResult && $health->shouldOpenContingency;

        if (! $allowsContingency) {
            return InvoiceIssuanceDecision::Blocked;
        }

        if ($cufd && function_exists('gzencode') && class_exists(\DOMDocument::class)) {
            return InvoiceIssuanceDecision::OfflineDigital;
        }

        $hasCafc = SinCafcRange::query()->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('sin_point_of_sale_id', $sale->sin_point_of_sale_id)
            ->where('document_sector_code', (int) $sale->document_sector_code)
            ->whereIn('range_status', [CafcRangeStatus::Available, CafcRangeStatus::InUse])
            ->whereDate('authorized_from', '<=', $sale->issued_at)
            ->whereDate('authorized_until', '>=', $sale->issued_at)
            ->whereColumn('next_number', '<=', 'range_end')
            ->exists();

        return $hasCafc
            ? InvoiceIssuanceDecision::ManualCafcRequired
            : InvoiceIssuanceDecision::Blocked;
    }

    private function forcedOfflineHealth(): SiatHealthCheckResult
    {
        return new SiatHealthCheckResult(
            available: false,
            errorType: SiatErrorType::NoInternet,
            userMessage: 'La emisión fuera de línea está activada en Parámetros.',
            technicalMessage: 'Emisión fuera de línea forzada por configuración de la empresa.',
            operation: 'FORCED_OFFLINE_EMISSION',
            wsdlUrl: '',
            durationMs: 0,
            requestDurationMs: 0,
            attempts: 0,
            shouldOpenContingency: true,
            checkedAt: now()->toIso8601String(),
        );
    }

    private function hasPendingOfflineInvoices(Sale $sale): bool
    {
        return SinInvoiceIssue::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('sin_point_of_sale_id', $sale->sin_point_of_sale_id)
            ->where('emission_mode', InvoiceEmissionMode::OfflineDigital)
            ->whereNotIn('fiscal_status', [
                InvoiceFiscalStatus::ValidatedAfterContingency,
                InvoiceFiscalStatus::Observed,
                InvoiceFiscalStatus::Rejected,
            ])
            ->where(function ($query): void {
                $query->whereNull('sin_significant_event_id')
                    ->orWhereHas('significantEvent', fn ($event) => $event
                        ->where(function ($status): void {
                            $status->where('manual_review_required', false)
                                ->orWhere('event_status', '!=', SignificantEventStatus::Failed);
                        }));
            })
            ->exists();
    }

    private function eventCufd(Sale $sale): ?SinCufd
    {
        $eventCufdId = SinSignificantEvent::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('sin_point_of_sale_id', $sale->sin_point_of_sale_id)
            ->whereNull('closed_at')
            ->whereNotIn('event_status', [
                SignificantEventStatus::Completed,
                SignificantEventStatus::Expired,
            ])
            ->where(function ($query): void {
                $query->where('event_status', '!=', SignificantEventStatus::Failed)
                    ->orWhere('manual_review_required', false);
            })
            ->latest('started_at')
            ->value('sin_cufd_id');

        if (! $eventCufdId) {
            return null;
        }

        return SinCufd::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('sin_point_of_sale_id', $sale->sin_point_of_sale_id)
            ->find($eventCufdId);
    }

    private function prepareInvoice(
        Sale $sale,
        SinApiToken $token,
        SinAuthorization $authorization,
        SinCuis $cuis,
        SinCufd $cufd,
        InvoiceIssuanceDecision $decision,
        SiatErrorType $communicationError,
        bool $testForced = false,
    ): SinInvoiceIssue {
        return DB::transaction(function () use ($sale, $token, $authorization, $cuis, $cufd, $decision, $communicationError, $testForced): SinInvoiceIssue {
            $lockedSale = Sale::query()->withoutGlobalScope('company')
                ->with(['company', 'user', 'customer', 'pointOfSale.branch', 'items'])
                ->where('company_id', $sale->company_id)
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($existing = $this->existingInvoice($lockedSale, true)) {
                return $existing;
            }

            $documentSectorCode = (int) $lockedSale->document_sector_code;
            $invoiceDocumentTypeCode = InvoiceDocumentSector::invoiceDocumentTypeCode($documentSectorCode);
            $number = $this->reserveNumber((int) $lockedSale->company_id, $documentSectorCode);
            $offline = $decision === InvoiceIssuanceDecision::OfflineDigital;
            $emissionCode = $offline ? self::EMISSION_OFFLINE : self::EMISSION_ONLINE;
            $cuf = $this->cufGenerator->generate(
                (string) $authorization->tax_id,
                $lockedSale->issued_at,
                (int) $lockedSale->pointOfSale->branch->branch_code,
                $authorization->modality_code->value,
                $emissionCode,
                $invoiceDocumentTypeCode,
                $documentSectorCode,
                $number,
                (int) $lockedSale->pointOfSale->point_of_sale_code,
                (string) $cufd->control_code,
            );
            $payload = $this->invoicePayload($lockedSale, $authorization, $cufd, $number, $cuf);
            $xml = $this->xmlSigner->sign($this->xmlBuilder->build($payload, $documentSectorCode), $lockedSale);

            if ($xml === '') {
                throw ValidationException::withMessages(['xml' => 'No se pudo generar el XML fiscal.']);
            }

            $this->xmlValidator->validate($xml, $documentSectorCode);

            $gzip = gzencode($xml, 9);

            if ($gzip === false) {
                throw new RuntimeException('No se pudo comprimir el XML fiscal.');
            }

            $paths = $this->storeXmlArtifacts((int) $lockedSale->company_id, $cuf, $xml, $gzip);
            $invoice = SinInvoiceIssue::query()->create([
                'company_id' => $lockedSale->company_id,
                'sale_id' => $lockedSale->id,
                'user_id' => $lockedSale->user_id,
                'customer_id' => $lockedSale->customer_id,
                'sin_api_token_id' => $token->id,
                'sin_authorization_id' => $authorization->id,
                'sin_branch_id' => $lockedSale->pointOfSale->sin_branch_id,
                'sin_point_of_sale_id' => $lockedSale->sin_point_of_sale_id,
                'sin_cuis_id' => $cuis->id,
                'sin_cufd_id' => $cufd->id,
                'tax_id' => $authorization->tax_id,
                'environment_code' => $authorization->environment_code,
                'modality_code' => $authorization->modality_code,
                'emission_type_code' => $emissionCode,
                'document_sector_code' => $documentSectorCode,
                'invoice_document_type_code' => $invoiceDocumentTypeCode,
                'emission_mode' => $offline ? InvoiceEmissionMode::OfflineDigital : InvoiceEmissionMode::Online,
                'commercial_status' => InvoiceCommercialStatus::Confirmed,
                'fiscal_status' => $offline ? InvoiceFiscalStatus::OfflineIssued : InvoiceFiscalStatus::PendingOnlineSend,
                'branch_code' => $lockedSale->pointOfSale->branch->branch_code,
                'point_of_sale_code' => $lockedSale->pointOfSale->point_of_sale_code,
                'attempted_invoice_number' => $number,
                'invoice_number' => $number,
                'cuf' => $cuf,
                'cufd_code' => $cufd->cufd_code,
                'control_code' => $cufd->control_code,
                'status_label' => $offline ? 'Emitida fuera de linea' : 'Pendiente de envio',
                'xml_path' => $paths['xml'],
                'gzip_path' => $paths['gzip'],
                'hash_file' => hash('sha256', $gzip),
                'subtotal_amount' => $lockedSale->subtotal_amount,
                'discount_amount' => $lockedSale->discount_amount,
                'total_amount' => $lockedSale->total_amount,
                'taxable_amount' => $lockedSale->total_amount_subject_to_vat,
                'payload' => $payload,
                'issued_at' => $lockedSale->issued_at,
            ]);

            if ($offline) {
                $event = $this->openEvent($lockedSale, $invoice, $token, $authorization, $cuis, $cufd, $communicationError);
                $invoice->forceFill(['sin_significant_event_id' => $event->id])->save();
                $invoice->loadMissing(['company', 'customer', 'pointOfSale.branch']);
                $pdf = $this->pdf->render($invoice);
                $pdfPath = 'invoices/'.$lockedSale->company_id.'/'.$lockedSale->issued_at->format('Y/m').'/'.$cuf.'.pdf';
                $this->putImmutable($pdfPath, $pdf);
                $invoice->forceFill(['pdf_path' => $pdfPath, 'pdf_hash' => hash('sha256', $pdf)])->save();
            }

            SinFiscalStatusHistory::query()->create([
                'company_id' => $lockedSale->company_id,
                'sin_invoice_issue_id' => $invoice->id,
                'sin_significant_event_id' => $invoice->sin_significant_event_id,
                'user_id' => $lockedSale->user_id,
                'from_status' => InvoiceFiscalStatus::NotIssued,
                'to_status' => $invoice->fiscal_status,
                'emission_mode' => $invoice->emission_mode,
                'reason_code' => $testForced ? 'TEST_FORCED_CONTINGENCY' : ($offline ? 'COMMUNICATION_CONTINGENCY' : 'ONLINE_PREPARED'),
                'reason' => $testForced ? 'Factura fuera de línea forzada por el laboratorio SIAT.' : ($offline ? 'Factura emitida localmente durante contingencia.' : 'Factura preparada para envio en linea.'),
                'changed_at' => now(),
            ]);
            $this->commercialEffects->confirmLocked($lockedSale);

            return $invoice->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $correctedArtifact */
    private function sendOnline(SinInvoiceIssue $invoice, bool $forceNewAttempt = false, ?string $gzipOverride = null, array $correctedArtifact = []): InvoiceIssuanceResult
    {
        $attempt = DB::transaction(function () use ($invoice, $forceNewAttempt, $correctedArtifact): SinSiatAttempt {
            $locked = SinInvoiceIssue::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($invoice->id);
            $existing = $locked->attempts()->latest('attempt_number')->first();

            if ($existing && ! $forceNewAttempt) {
                return $existing;
            }

            return SinSiatAttempt::query()->create([
                'company_id' => $locked->company_id,
                'sin_invoice_issue_id' => $locked->id,
                'user_id' => $locked->user_id,
                'idempotency_key' => (string) Str::uuid(),
                'operation' => SiatOperation::ReceiveInvoice,
                'attempt_number' => $existing ? $existing->attempt_number + 1 : 1,
                'attempt_status' => SiatAttemptStatus::Sending,
                'endpoint' => InvoiceDocumentSector::wsdlKey((int) $locked->document_sector_code),
                'request_hash' => $correctedArtifact['hash'] ?? $locked->hash_file,
                'request_payload' => ['cuf' => $locked->cuf, 'hash' => $correctedArtifact['hash'] ?? $locked->hash_file, ...$correctedArtifact],
                'started_at' => now(),
            ]);
        });

        if ($attempt->attempt_status !== SiatAttemptStatus::Sending) {
            return $this->existingResult($invoice->refresh());
        }

        $gzip = $gzipOverride ?? Storage::disk('local')->get((string) $invoice->gzip_path);

        try {
            $response = $this->siatClient->send($invoice, $gzip);

            return $this->completeOnline($invoice, $attempt, $response);
        } catch (InvoiceTransportException $exception) {
            return $this->failOnline($invoice, $attempt, $exception);
        } catch (Throwable $exception) {
            return $this->failOnline($invoice, $attempt, new InvoiceTransportException(
                $exception->getMessage(),
                false,
                SiatErrorType::Unknown,
                $exception,
            ));
        }
    }

    private function completeOnline(
        SinInvoiceIssue $invoice,
        SinSiatAttempt $attempt,
        InvoiceSiatResponse $response,
    ): InvoiceIssuanceResult {
        return DB::transaction(function () use ($invoice, $attempt, $response): InvoiceIssuanceResult {
            $locked = SinInvoiceIssue::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($invoice->id);
            $lockedAttempt = SinSiatAttempt::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($attempt->id);
            $statusCode = $this->findInt($response->data, ['codigoEstado']);
            $transaction = $this->findBoolean($response->data, 'transaccion') ?? $statusCode === 908;
            $toStatus = match (true) {
                $statusCode === 908 && $transaction => InvoiceFiscalStatus::Validated,
                $statusCode === 904 => InvoiceFiscalStatus::Observed,
                default => InvoiceFiscalStatus::Rejected,
            };
            $message = $this->responseMessage($response->data, $toStatus);
            $receptionCode = $this->findValue($response->data, ['codigoRecepcion']);

            $locked->forceFill([
                'reception_code' => $receptionCode,
                'status_code' => $statusCode,
                'status_label' => match ($toStatus) {
                    InvoiceFiscalStatus::Validated => 'Validada',
                    InvoiceFiscalStatus::Observed => 'Observada',
                    default => 'Rechazada',
                },
                'fiscal_status' => $toStatus,
                'failure_category' => null,
                'transaccion' => $transaction,
                'response' => $response->data,
                'message' => $message,
                'duration_ms' => $response->durationMs,
                'sent_at' => now(),
            ])->save();
            $lockedAttempt->forceFill([
                'attempt_status' => SiatAttemptStatus::Succeeded,
                'reception_code' => $receptionCode,
                'siat_status_code' => $statusCode,
                'duration_ms' => $response->durationMs,
                'message' => $message,
                'response' => $response->data,
                'finished_at' => now(),
            ])->save();
            $this->storeResponseMessages($lockedAttempt, $response->data, $toStatus);
            $this->recordTransition($locked, InvoiceFiscalStatus::PendingOnlineSend, $toStatus, $lockedAttempt, $message);

            return new InvoiceIssuanceResult(InvoiceIssuanceDecision::Online, $locked->refresh(), $message);
        }, 3);
    }

    private function failOnline(
        SinInvoiceIssue $invoice,
        SinSiatAttempt $attempt,
        InvoiceTransportException $exception,
    ): InvoiceIssuanceResult {
        return DB::transaction(function () use ($invoice, $attempt, $exception): InvoiceIssuanceResult {
            $locked = SinInvoiceIssue::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($invoice->id);
            $lockedAttempt = SinSiatAttempt::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($attempt->id);
            $toStatus = $exception->mayHaveReachedSiat
                ? InvoiceFiscalStatus::UncertainSend
                : InvoiceFiscalStatus::PendingOnlineSend;
            $message = $exception->mayHaveReachedSiat
                ? 'El envio puede haber llegado al SIN. No se reenviara ni se creara otra factura automaticamente.'
                : 'La solicitud no llego a enviarse al SIN y queda pendiente usando la misma factura.';

            $locked->forceFill([
                'status_label' => $exception->mayHaveReachedSiat ? 'Envio incierto' : 'Pendiente de envio',
                'fiscal_status' => $toStatus,
                'failure_category' => $exception->errorType->failureCategory(),
                'transaccion' => false,
                'message' => $message,
                'sent_at' => $exception->mayHaveReachedSiat ? now() : null,
            ])->save();
            $lockedAttempt->forceFill([
                'attempt_status' => $exception->mayHaveReachedSiat ? SiatAttemptStatus::Uncertain : SiatAttemptStatus::Failed,
                'failure_category' => $exception->errorType->failureCategory(),
                'message' => Str::limit($this->logSanitizer->text($exception->getMessage()) ?? '', 1000),
                'finished_at' => now(),
            ])->save();

            if ($toStatus !== InvoiceFiscalStatus::PendingOnlineSend) {
                $this->recordTransition($locked, InvoiceFiscalStatus::PendingOnlineSend, $toStatus, $lockedAttempt, $message);
            }

            return new InvoiceIssuanceResult(InvoiceIssuanceDecision::Online, $locked->refresh(), $message);
        }, 3);
    }

    private function reserveNumber(int $companyId, int $documentSectorCode): int
    {
        $maximum = (int) SinInvoiceIssue::query()->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('document_sector_code', $documentSectorCode)
            ->max('attempted_invoice_number');
        DB::table('sin_invoice_sequences')->insertOrIgnore([
            'company_id' => $companyId,
            'document_sector_code' => $documentSectorCode,
            'next_number' => $maximum + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sequence = SinInvoiceSequence::query()->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('document_sector_code', $documentSectorCode)
            ->lockForUpdate()
            ->firstOrFail();
        $number = $sequence->next_number;
        $sequence->increment('next_number');

        return $number;
    }

    /** @return array{cabecera: array<string, mixed>, detalle: array<int, array<string, mixed>>} */
    private function invoicePayload(Sale $sale, SinAuthorization $authorization, SinCufd $cufd, int $number, string $cuf): array
    {
        $documentSectorCode = (int) $sale->document_sector_code;

        return [
            'cabecera' => [
                'nitEmisor' => $authorization->tax_id,
                'razonSocialEmisor' => $authorization->legal_name ?: $sale->company->legal_name ?: $sale->company->name,
                'municipio' => $sale->company->city ?: 'Bolivia',
                'telefono' => $sale->company->phone,
                'numeroFactura' => $number,
                'cuf' => $cuf,
                'cufd' => $cufd->cufd_code,
                'codigoSucursal' => $sale->pointOfSale->branch->branch_code,
                'direccion' => $cufd->address ?: $sale->company->address ?: 'Sin direccion registrada',
                'codigoPuntoVenta' => $sale->pointOfSale->point_of_sale_code,
                'fechaEmision' => SiatDateTime::extended($sale->issued_at),
                'nombreRazonSocial' => $sale->customer->name,
                'codigoTipoDocumentoIdentidad' => $sale->customer->identity_document_type_code,
                'numeroDocumento' => $sale->customer->document_number,
                'complemento' => $sale->customer->document_complement,
                'codigoCliente' => $sale->customer->customer_code,
                'codigoMetodoPago' => $sale->payment_method_code,
                'numeroTarjeta' => $sale->payment_method_code === 2 ? $sale->masked_card_number : null,
                'montoTotal' => $this->amount($sale->total_amount),
                'montoTotalSujetoIva' => $documentSectorCode === InvoiceDocumentSector::ZERO_RATE
                    ? '0'
                    : $this->amount($sale->total_amount_subject_to_vat),
                'codigoMoneda' => $sale->currency_code,
                'tipoCambio' => $this->amount($sale->exchange_rate),
                'montoTotalMoneda' => $this->amount($sale->total_amount_currency),
                'montoGiftCard' => bccomp((string) $sale->gift_card_amount, '0', 2) > 0 ? $this->amount($sale->gift_card_amount) : null,
                'descuentoAdicional' => $this->amount($sale->discount_amount),
                'codigoExcepcion' => null,
                'cafc' => null,
                'leyenda' => $this->legend((int) $sale->company_id, (string) $sale->economic_activity_code),
                'usuario' => Str::limit($sale->user->name ?: $sale->user->email, 50, ''),
                'codigoDocumentoSector' => $documentSectorCode,
            ],
            'detalle' => $sale->items->map(function ($item) use ($documentSectorCode): array {
                $detail = [
                    'actividadEconomica' => $item->economic_activity_code,
                    'codigoProductoSin' => $item->siat_product_code,
                    'codigoProducto' => $item->internal_code,
                    'descripcion' => $item->description,
                    'cantidad' => $this->quantity($item->quantity),
                    'unidadMedida' => $item->measurement_unit_code,
                    'precioUnitario' => $this->amount($item->unit_price),
                    'montoDescuento' => $this->amount($item->discount_amount),
                    'subTotal' => $this->amount($item->subtotal_amount),
                ];

                if ($documentSectorCode === InvoiceDocumentSector::PURCHASE_SALE) {
                    $detail['numeroSerie'] = null;
                    $detail['numeroImei'] = null;
                }

                return $detail;
            })->all(),
        ];
    }

    /** @return array{xml: string, gzip: string} */
    private function storeXmlArtifacts(int $companyId, string $cuf, string $xml, string $gzip): array
    {
        $base = 'invoices/'.$companyId.'/'.now()->format('Y/m').'/'.$cuf;
        $xmlPath = $base.'.xml';
        $gzipPath = $base.'.xml.gz';
        $this->putImmutable($xmlPath, $xml);
        $this->putImmutable($gzipPath, $gzip);

        return ['xml' => $xmlPath, 'gzip' => $gzipPath];
    }

    private function putImmutable(string $path, string $contents): void
    {
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            if (! hash_equals(hash('sha256', $disk->get($path)), hash('sha256', $contents))) {
                throw new RuntimeException('Ya existe un artefacto fiscal diferente en la ruta inmutable.');
            }

            return;
        }

        if (! $disk->put($path, $contents)) {
            throw new RuntimeException('No se pudo guardar el artefacto fiscal.');
        }
    }

    private function openEvent(
        Sale $sale,
        SinInvoiceIssue $invoice,
        SinApiToken $token,
        SinAuthorization $authorization,
        SinCuis $cuis,
        SinCufd $cufd,
        SiatErrorType $errorType,
    ): SinSignificantEvent {
        // Locking the point of sale serializes the first event creation too. A
        // SELECT ... FOR UPDATE over an empty events result cannot prevent two
        // concurrent issuances from both creating an OPEN event.
        SinPointOfSale::query()->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->whereKey($sale->sin_point_of_sale_id)
            ->lockForUpdate()
            ->firstOrFail();

        $event = SinSignificantEvent::query()->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('sin_point_of_sale_id', $sale->sin_point_of_sale_id)
            ->where('event_status', SignificantEventStatus::Open)
            ->lockForUpdate()
            ->first();

        if ($event) {
            if ($authorization->force_offline_emission && ! $event->requires_manual_processing) {
                $event->update(['requires_manual_processing' => true]);
            }

            return $event;
        }

        $internetFailure = in_array($errorType, [SiatErrorType::NoInternet, SiatErrorType::DnsUnavailable], true);
        $eventCode = $internetFailure
            ? (int) config('siat.invoice_issuance.internet_outage_event_code', 1)
            : (int) config('siat.invoice_issuance.siat_unavailable_event_code', 2);
        $description = SinCatalogItem::query()->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('catalog_key', 'eventos_significativos')
            ->where('classifier_code', (string) $eventCode)
            ->value('description');

        return SinSignificantEvent::query()->create([
            'company_id' => $sale->company_id,
            'user_id' => $sale->user_id,
            'sin_invoice_issue_id' => $invoice->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $sale->pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $sale->sin_point_of_sale_id,
            'sin_cuis_id' => $cuis->id,
            'sin_cufd_id' => $cufd->id,
            'event_code' => $eventCode,
            'event_description' => $description ?: ($internetFailure
                ? 'Corte del servicio de Internet.'
                : 'Inaccesibilidad al servicio web del SIN.'),
            'event_status' => SignificantEventStatus::Open,
            'started_at' => $sale->issued_at,
            'detected_at' => now(),
            'status_label' => 'Pendiente de registro',
            'requires_manual_processing' => true,
        ]);
    }

    private function recordTransition(
        SinInvoiceIssue $invoice,
        InvoiceFiscalStatus $from,
        InvoiceFiscalStatus $to,
        ?SinSiatAttempt $attempt,
        string $reason,
    ): void {
        SinFiscalStatusHistory::query()->create([
            'company_id' => $invoice->company_id,
            'sin_invoice_issue_id' => $invoice->id,
            'sin_siat_attempt_id' => $attempt?->id,
            'sin_significant_event_id' => $invoice->sin_significant_event_id,
            'user_id' => $invoice->user_id,
            'from_status' => $from,
            'to_status' => $to,
            'emission_mode' => $invoice->emission_mode,
            'reason' => $reason,
            'changed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $response */
    private function storeResponseMessages(SinSiatAttempt $attempt, array $response, InvoiceFiscalStatus $status): void
    {
        foreach ($this->messageRows($response) as $row) {
            $description = trim((string) ($row['descripcion'] ?? $row['mensaje'] ?? $row['descripcionMensaje'] ?? ''));

            if ($description === '') {
                continue;
            }

            $code = (string) ($row['codigo'] ?? $row['codigoMensaje'] ?? '');
            SinResponseMessage::query()->firstOrCreate([
                'company_id' => $attempt->company_id,
                'sin_siat_attempt_id' => $attempt->id,
                'message_key' => hash('sha256', $code.'|'.$description),
            ], [
                'service' => SiatOperation::ReceiveInvoice->value,
                'message_code' => $code !== '' ? $code : null,
                'severity' => match ($status) {
                    InvoiceFiscalStatus::Validated => SiatMessageSeverity::Info,
                    InvoiceFiscalStatus::Observed => SiatMessageSeverity::Warning,
                    default => SiatMessageSeverity::Error,
                },
                'description' => $description,
                'raw_data' => $row,
                'received_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function messageRows(array $data): array
    {
        $rows = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && in_array($key, ['mensajesList', 'mensajes', 'mensajeList'], true)) {
                $candidates = array_is_list($value) ? $value : [$value];
                foreach ($candidates as $candidate) {
                    if (is_array($candidate)) {
                        $rows[] = $candidate;
                    }
                }
            } elseif (is_array($value)) {
                $rows = [...$rows, ...$this->messageRows($value)];
            }
        }

        return $rows;
    }

    private function existingInvoice(Sale $sale, bool $locked = false): ?SinInvoiceIssue
    {
        $query = SinInvoiceIssue::query()->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('sale_id', $sale->id);

        return ($locked ? $query->lockForUpdate() : $query)->first();
    }

    private function existingResult(SinInvoiceIssue $invoice): InvoiceIssuanceResult
    {
        $decision = $invoice->emission_mode === InvoiceEmissionMode::OfflineDigital
            ? InvoiceIssuanceDecision::OfflineDigital
            : InvoiceIssuanceDecision::Online;

        return new InvoiceIssuanceResult($decision, $invoice, 'La venta ya tiene una factura; se devolvio la emision existente.');
    }

    private function blocked(string $message): InvoiceIssuanceResult
    {
        return new InvoiceIssuanceResult(InvoiceIssuanceDecision::Blocked, null, $message);
    }

    private function legend(int $companyId, string $economicActivityCode): string
    {
        return SinCatalogItem::query()->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('catalog_key', 'leyendas_factura')
            ->where('classifier_code', $economicActivityCode)
            ->active()
            ->inRandomOrder()
            ->value('description')
            ?: 'Ley Nro 453: El proveedor debe brindar atencion sin discriminacion.';
    }

    private function amount(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 5, '.', ''), '0'), '.');
    }

    private function maskCardNumber(string $cardNumber): string
    {
        $digits = preg_replace('/\D+/', '', $cardNumber) ?? '';
        if (strlen($digits) !== 16) {
            throw ValidationException::withMessages(['card_number' => 'El número de tarjeta debe contener exactamente 16 dígitos.']);
        }

        return substr($digits, 0, 4).'00000000'.substr($digits, -4);
    }

    /** @param array<string, mixed> $data
     * @param  array<int, string>  $keys
     */
    private function findValue(array $data, array $keys): ?string
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $keys, true) && is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }

            if (is_array($value) && ($found = $this->findValue($value, $keys)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data
     * @param  array<int, string>  $keys
     */
    private function findInt(array $data, array $keys): ?int
    {
        $value = $this->findValue($data, $keys);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $data */
    private function findBoolean(array $data, string $key): ?bool
    {
        foreach ($data as $currentKey => $value) {
            if ($currentKey === $key) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (is_array($value) && ($found = $this->findBoolean($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $response */
    private function responseMessage(array $response, InvoiceFiscalStatus $status): string
    {
        $message = $this->findValue($response, ['descripcion', 'mensaje', 'descripcionMensaje']);

        return $message ?: match ($status) {
            InvoiceFiscalStatus::Validated => 'Factura validada por el SIN.',
            InvoiceFiscalStatus::Observed => 'Factura observada por el SIN.',
            default => 'Factura rechazada por el SIN.',
        };
    }
}
