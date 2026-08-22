<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\InvoiceCommercialStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SiatModality;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCafcRange;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinFiscalStatusHistory;
use App\Models\SinInvoiceIssue;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Siat\InvoiceXmlValidator;
use App\Services\Siat\PurchaseSaleInvoiceXmlBuilder;
use App\Services\Siat\SiatCufGenerator;
use App\Services\Siat\SiatDateTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ManualCafcService
{
    private const DOCUMENT_SECTOR = 1;

    public function __construct(
        private readonly PurchaseSaleInvoiceXmlBuilder $xmlBuilder,
        private readonly InvoiceXmlValidator $xmlValidator,
        private readonly SiatCufGenerator $cufGenerator,
        private readonly InvoiceTotalsCalculator $totals,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function registerRange(array $attributes, User $actor): SinCafcRange
    {
        return DB::transaction(function () use ($attributes, $actor): SinCafcRange {
            $branchId = (int) $attributes['sin_branch_id'];
            $pointId = isset($attributes['sin_point_of_sale_id']) ? (int) $attributes['sin_point_of_sale_id'] : null;

            if ($pointId !== null) {
                $this->pointOfSale((int) $actor->company_id, $branchId, $pointId);
            }

            $eventId = isset($attributes['sin_significant_event_id']) ? (int) $attributes['sin_significant_event_id'] : null;
            if ($eventId !== null) {
                $eventIsValid = SinSignificantEvent::query()->withoutGlobalScope('company')
                    ->whereKey($eventId)
                    ->where('company_id', $actor->company_id)
                    ->where('sin_branch_id', $branchId)
                    ->where('sin_point_of_sale_id', $pointId)
                    ->where('transaccion', true)
                    ->exists();

                if (! $eventIsValid) {
                    throw ValidationException::withMessages(['event_code' => 'El evento debe estar registrado ante el SIN y corresponder al mismo punto de venta del CAFC.']);
                }
            }

            $start = (int) $attributes['range_start'];
            $end = (int) $attributes['range_end'];
            $from = CarbonImmutable::parse((string) $attributes['authorized_from'])->startOfDay();
            $until = CarbonImmutable::parse((string) $attributes['authorized_until'])->endOfDay();

            if ($start < 1 || $end < $start || $until->lt($from)) {
                throw ValidationException::withMessages(['range_end' => 'El rango o las fechas de autorización no son válidos.']);
            }

            return SinCafcRange::query()->withoutGlobalScope('company')->create([
                'company_id' => $actor->company_id,
                'sin_branch_id' => $branchId,
                'sin_point_of_sale_id' => $pointId,
                'sin_significant_event_id' => $eventId,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                'cafc_code' => trim((string) $attributes['cafc_code']),
                'document_sector_code' => (int) ($attributes['document_sector_code'] ?? self::DOCUMENT_SECTOR),
                'range_start' => $start,
                'range_end' => $end,
                'next_number' => $start,
                'range_status' => CafcRangeStatus::Available,
                'authorized_from' => $from,
                'authorized_until' => $until,
                'used_count' => 0,
                'cancelled_count' => 0,
                'notes' => $attributes['notes'] ?? null,
            ]);
        }, 3);
    }

    public function availableRanges(int $companyId, int $branchId, ?int $pointId, DateTimeInterface $at): Builder
    {
        return SinCafcRange::query()->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('sin_branch_id', $branchId)
            ->where(fn (Builder $query) => $query->whereNull('sin_point_of_sale_id')->when(
                $pointId !== null,
                fn (Builder $nested) => $nested->orWhere('sin_point_of_sale_id', $pointId)
            ))
            ->whereIn('range_status', [CafcRangeStatus::Available, CafcRangeStatus::InUse])
            ->whereDate('authorized_from', '<=', $at)
            ->whereDate('authorized_until', '>=', $at)
            ->whereRaw('used_count + cancelled_count < range_end - range_start + 1');
    }

    public function recordUsed(
        SinCafcRange $range,
        SinPointOfSale $pointOfSale,
        int $number,
        DateTimeInterface $issuedAt,
        User $actor,
        ?SinSignificantEvent $event = null,
    ): SinManualContingencyInvoice {
        return $this->consume($range, $pointOfSale, $number, $issuedAt, $actor, $event, false, null);
    }

    public function recordCancelled(
        SinCafcRange $range,
        SinPointOfSale $pointOfSale,
        int $number,
        DateTimeInterface $issuedAt,
        User $actor,
        string $reason,
        ?SinSignificantEvent $event = null,
    ): SinManualContingencyInvoice {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['void_reason' => 'Debe indicar el motivo de anulación.']);
        }

        return $this->consume($range, $pointOfSale, $number, $issuedAt, $actor, $event, true, $reason);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function transcribe(
        SinManualContingencyInvoice $manual,
        Customer $customer,
        array $data,
        array $lines,
        User $actor,
        ?UploadedFile $evidence = null,
    ): SinManualContingencyInvoice {
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Debe registrar al menos un producto o servicio.']);
        }

        $prepared = $this->prepareLines((int) $manual->company_id, $lines);
        $documentSectorCode = (int) $manual->document_sector_code;
        $calculation = $this->totals->calculate($prepared, $data['discount_amount'] ?? 0, 'FIXED', null, (int) ($data['currency_code'] ?? 1), 1, 0, $documentSectorCode);
        $prepared = array_map(function (array $line, int $index) use ($calculation): array {
            $amounts = $calculation['items'][$index];

            return [...$line, 'quantity' => $amounts['quantity'], 'unit_price' => $amounts['unit_price'],
                'discount_amount' => $amounts['discount_amount'], 'subtotal_amount' => $amounts['subtotal']];
        }, $prepared, array_keys($prepared));
        $subtotal = $calculation['subtotal_sum'];
        $discount = $calculation['discount_additional'];
        $total = $calculation['total_amount'];

        if (bccomp(number_format((float) $data['total_amount'], 2, '.', ''), $total, 2) !== 0) {
            throw ValidationException::withMessages(['total_amount' => 'El total no coincide con el detalle y los descuentos.']);
        }

        $evidenceData = $evidence ? $this->storeEvidence($manual, $evidence) : null;

        return DB::transaction(function () use ($manual, $customer, $data, $prepared, $actor, $subtotal, $discount, $total, $evidenceData): SinManualContingencyInvoice {
            $locked = SinManualContingencyInvoice::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($manual->id);

            if ((int) $locked->company_id !== (int) $actor->company_id || (int) $customer->company_id !== (int) $actor->company_id) {
                throw ValidationException::withMessages(['customer_id' => 'El CAFC y el cliente deben pertenecer a la empresa activa.']);
            }
            if ($locked->manual_status === ManualContingencyInvoiceStatus::Cancelled) {
                throw ValidationException::withMessages(['manual_invoice_number' => 'Una factura física anulada no puede transcribirse.']);
            }
            if ($locked->manual_status !== ManualContingencyInvoiceStatus::PendingTranscription || $locked->sin_invoice_issue_id !== null) {
                throw ValidationException::withMessages(['manual_invoice_number' => 'Esta factura manual ya fue transcrita.']);
            }

            $locked->loadMissing(['company', 'cafcRange', 'pointOfSale.branch', 'significantEvent']);
            $this->validateRange($locked->cafcRange, $locked->pointOfSale, (int) $locked->manual_invoice_number, $locked->issued_manually_at);
            [$token, $authorization, $cuis, $cufd] = $this->fiscalConfiguration($locked);

            $cuf = $this->cufGenerator->generate(
                $authorization->tax_id,
                $locked->issued_manually_at,
                (int) $locked->pointOfSale->branch->branch_code,
                $authorization->modality_code->value,
                2,
                1,
                (int) $locked->document_sector_code,
                (int) $locked->manual_invoice_number,
                (int) $locked->pointOfSale->point_of_sale_code,
                (string) $cufd->control_code,
            );

            $paymentMethod = (int) ($data['payment_method_code'] ?? 1);
            $currency = (int) ($data['currency_code'] ?? 1);
            $payload = $this->payload($locked, $customer, $prepared, $authorization, $cufd, $cuf, $subtotal, $discount, $total, $paymentMethod, $currency, $actor);
            $documentSectorCode = (int) $locked->document_sector_code;
            $xml = $this->xmlBuilder->build($payload, $documentSectorCode);
            $this->xmlValidator->validate($xml, $documentSectorCode);
            $gzip = gzencode($xml, 9);
            if ($xml === '' || $gzip === false) {
                throw new RuntimeException('No fue posible generar el XML de la factura manual.');
            }
            $hash = hash('sha256', $gzip);
            $paths = $this->storeXml((int) $locked->company_id, $cuf, $xml, $gzip);

            $issue = SinInvoiceIssue::query()->withoutGlobalScope('company')->create([
                'company_id' => $locked->company_id,
                'user_id' => $actor->id,
                'customer_id' => $customer->id,
                'sin_api_token_id' => $token->id,
                'sin_authorization_id' => $authorization->id,
                'sin_branch_id' => $locked->sin_branch_id,
                'sin_point_of_sale_id' => $locked->sin_point_of_sale_id,
                'sin_cuis_id' => $cuis->id,
                'sin_cufd_id' => $cufd->id,
                'sin_significant_event_id' => $locked->sin_significant_event_id,
                'tax_id' => $authorization->tax_id,
                'environment_code' => $authorization->environment_code,
                'modality_code' => SiatModality::ComputerizedOnline,
                'emission_type_code' => 2,
                'document_sector_code' => $locked->document_sector_code,
                'invoice_document_type_code' => 1,
                'emission_mode' => InvoiceEmissionMode::ManualCafc,
                'commercial_status' => InvoiceCommercialStatus::Confirmed,
                'fiscal_status' => InvoiceFiscalStatus::PendingPackage,
                'branch_code' => $locked->pointOfSale->branch->branch_code,
                'point_of_sale_code' => $locked->pointOfSale->point_of_sale_code,
                'attempted_invoice_number' => $locked->manual_invoice_number,
                'invoice_number' => null,
                'cuf' => $cuf,
                'cufd_code' => $cufd->cufd_code,
                'control_code' => $cufd->control_code,
                'status_label' => 'Manual pendiente de envío',
                'transaccion' => false,
                'xml_path' => $paths['xml'],
                'gzip_path' => $paths['gzip'],
                'hash_file' => $hash,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'taxable_amount' => $documentSectorCode === InvoiceDocumentSector::ZERO_RATE ? 0 : $total,
                'payload' => $payload,
                'issued_at' => $locked->issued_manually_at,
            ]);

            $locked->forceFill([
                'sin_invoice_issue_id' => $issue->id,
                'customer_id' => $customer->id,
                'transcribed_by_user_id' => $actor->id,
                'customer_name' => $customer->name,
                'identity_document_type_code' => $customer->identity_document_type_code,
                'document_number' => $customer->document_number,
                'document_complement' => $customer->document_complement,
                'customer_code' => $customer->customer_code,
                'payment_method_code' => $paymentMethod,
                'currency_code' => $currency,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'observations' => $data['observations'] ?? null,
                'original_document_path' => $evidenceData['path'] ?? null,
                'original_document_hash' => $evidenceData['hash'] ?? null,
                'xml_path' => $paths['xml'],
                'xml_hash' => hash('sha256', $xml),
                'manual_status' => ManualContingencyInvoiceStatus::PendingSend,
                'transcribed_at' => now(),
            ])->save();

            foreach ($prepared as $line) {
                $locked->items()->create($line + ['company_id' => $locked->company_id]);
            }

            SinFiscalStatusHistory::query()->create([
                'company_id' => $locked->company_id,
                'sin_invoice_issue_id' => $issue->id,
                'sin_significant_event_id' => $locked->sin_significant_event_id,
                'user_id' => $actor->id,
                'from_status' => InvoiceFiscalStatus::ManualPendingTranscription,
                'to_status' => InvoiceFiscalStatus::ManualPendingSend,
                'emission_mode' => InvoiceEmissionMode::ManualCafc,
                'reason_code' => 'MANUAL_CAFC_TRANSCRIBED',
                'reason' => 'Factura física transcrita; XML generado de forma inmutable.',
                'changed_at' => now(),
            ]);

            return $locked->refresh()->load(['items', 'invoice']);
        }, 3);
    }

    private function consume(SinCafcRange $range, SinPointOfSale $point, int $number, DateTimeInterface $issuedAt, User $actor, ?SinSignificantEvent $event, bool $cancelled, ?string $reason): SinManualContingencyInvoice
    {
        return DB::transaction(function () use ($range, $point, $number, $issuedAt, $actor, $event, $cancelled, $reason): SinManualContingencyInvoice {
            $locked = SinCafcRange::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($range->id);
            $this->validateRange($locked, $point, $number, $issuedAt);

            if ((int) $locked->company_id !== (int) $actor->company_id) {
                throw ValidationException::withMessages(['cafc_range_id' => 'El CAFC no pertenece a la empresa activa.']);
            }
            if ($event !== null && ((int) $event->company_id !== (int) $locked->company_id || (int) $event->sin_point_of_sale_id !== (int) $point->id)) {
                throw ValidationException::withMessages(['significant_event_id' => 'El evento no corresponde a la empresa y punto de venta.']);
            }
            if (SinManualContingencyInvoice::query()->withoutGlobalScope('company')
                ->where('company_id', $locked->company_id)
                ->where('sin_cafc_range_id', $locked->id)
                ->where('manual_invoice_number', $number)
                ->exists()) {
                throw ValidationException::withMessages(['manual_invoice_number' => 'El número ya fue utilizado o anulado.']);
            }

            $manual = SinManualContingencyInvoice::query()->withoutGlobalScope('company')->create([
                'company_id' => $locked->company_id,
                'sin_cafc_range_id' => $locked->id,
                'sin_significant_event_id' => $event?->id,
                'sin_branch_id' => $locked->sin_branch_id,
                'sin_point_of_sale_id' => $point->id,
                'created_by_user_id' => $actor->id,
                'voided_by_user_id' => $cancelled ? $actor->id : null,
                'manual_invoice_number' => $number,
                'document_sector_code' => $locked->document_sector_code,
                'manual_status' => $cancelled ? ManualContingencyInvoiceStatus::Cancelled : ManualContingencyInvoiceStatus::PendingTranscription,
                'issued_manually_at' => CarbonImmutable::instance($issuedAt),
                'void_reason' => $cancelled ? trim((string) $reason) : null,
                'voided_at' => $cancelled ? now() : null,
            ]);

            $cancelled ? $locked->increment('cancelled_count') : $locked->increment('used_count');
            $this->refreshNextNumber($locked->refresh(), $actor);

            return $manual->refresh();
        }, 3);
    }

    private function validateRange(SinCafcRange $range, SinPointOfSale $point, int $number, DateTimeInterface $issuedAt): void
    {
        if ((int) $point->company_id !== (int) $range->company_id || (int) $point->sin_branch_id !== (int) $range->sin_branch_id || ($range->sin_point_of_sale_id !== null && (int) $range->sin_point_of_sale_id !== (int) $point->id)) {
            throw ValidationException::withMessages(['sin_point_of_sale_id' => 'El punto de venta no está asignado al CAFC.']);
        }
        if (! $range->range_status->canConsume()) {
            throw ValidationException::withMessages(['cafc_range_id' => 'El rango CAFC no está disponible.']);
        }
        if ($number < $range->range_start || $number > $range->range_end) {
            throw ValidationException::withMessages(['manual_invoice_number' => 'El número está fuera del rango CAFC.']);
        }

        $date = CarbonImmutable::instance($issuedAt);
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['issued_manually_at' => 'La fecha original no puede estar en el futuro.']);
        }
        if ($date->toDateString() < $range->authorized_from->toDateString() || $date->toDateString() > $range->authorized_until->toDateString()) {
            throw ValidationException::withMessages(['issued_manually_at' => 'La fecha original está fuera de la vigencia del CAFC.']);
        }
    }

    private function refreshNextNumber(SinCafcRange $range, User $actor): void
    {
        $used = $range->manualInvoices()->pluck('manual_invoice_number')->map(static fn ($value): int => (int) $value)->flip();
        $next = $range->range_start;
        while ($next <= $range->range_end && $used->has($next)) {
            $next++;
        }
        $remaining = $range->range_end - $range->range_start + 1 - $range->used_count - $range->cancelled_count;
        $range->forceFill([
            'next_number' => $next,
            'range_status' => $remaining <= 0 ? CafcRangeStatus::Exhausted : CafcRangeStatus::InUse,
            'updated_by_user_id' => $actor->id,
        ])->save();
    }

    /** @param array<int, array<string, mixed>> $lines @return array<int, array<string, mixed>> */
    private function prepareLines(int $companyId, array $lines): array
    {
        $prepared = [];
        foreach ($lines as $index => $line) {
            $product = Product::query()->withoutGlobalScope('company')->where('company_id', $companyId)->find($line['product_id'] ?? null);
            if (! $product) {
                throw ValidationException::withMessages(["items.$index.product_id" => 'El producto no pertenece a la empresa activa.']);
            }
            $prepared[] = [
                'product_id' => $product->id, 'line_number' => $index + 1,
                'economic_activity_code' => $product->economic_activity_code,
                'siat_product_code' => $product->siat_product_code,
                'internal_code' => $product->internal_code, 'description' => $product->description,
                'measurement_unit_code' => $product->measurement_unit_code,
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                'discount_type' => 'FIXED', 'discount_amount' => $line['discount_amount'] ?? 0,
            ];
        }

        return $prepared;
    }

    /** @return array{SinApiToken, SinAuthorization, SinCuis, SinCufd} */
    private function fiscalConfiguration(SinManualContingencyInvoice $manual): array
    {
        $base = fn (string $model) => $model::query()->withoutGlobalScope('company')->where('company_id', $manual->company_id);
        $token = $base(SinApiToken::class)->first();
        $authorization = $base(SinAuthorization::class)->first();
        $cuis = $base(SinCuis::class)->usable()->where('sin_point_of_sale_id', $manual->sin_point_of_sale_id)->latest('requested_at')->first();
        $cufd = $base(SinCufd::class)->current()->where('sin_point_of_sale_id', $manual->sin_point_of_sale_id)->latest('requested_at')->first();
        if (! $token || ! $authorization || ! $cuis || ! $cufd || $authorization->modality_code !== SiatModality::ComputerizedOnline) {
            throw ValidationException::withMessages(['cafc_range_id' => 'Falta token, autorización, CUIS o CUFD vigente para transcribir y enviar.']);
        }

        return [$token, $authorization, $cuis, $cufd];
    }

    /** @param array<int, array<string, mixed>> $lines @return array<string, mixed> */
    private function payload(SinManualContingencyInvoice $manual, Customer $customer, array $lines, SinAuthorization $authorization, SinCufd $cufd, string $cuf, string $subtotal, string $discount, string $total, int $paymentMethod, int $currency, User $actor): array
    {
        return ['cabecera' => [
            'nitEmisor' => $authorization->tax_id,
            'razonSocialEmisor' => $authorization->legal_name ?: $manual->company->legal_name,
            'municipio' => $manual->company->city ?: 'Bolivia', 'telefono' => $manual->company->phone,
            'numeroFactura' => $manual->manual_invoice_number, 'cuf' => $cuf, 'cufd' => $cufd->cufd_code,
            'codigoSucursal' => $manual->pointOfSale->branch->branch_code,
            'direccion' => $cufd->address ?: $manual->company->address ?: 'Sin dirección registrada',
            'codigoPuntoVenta' => $manual->pointOfSale->point_of_sale_code,
            'fechaEmision' => SiatDateTime::extended($manual->issued_manually_at),
            'nombreRazonSocial' => $customer->name,
            'codigoTipoDocumentoIdentidad' => $customer->identity_document_type_code,
            'numeroDocumento' => $customer->document_number, 'complemento' => $customer->document_complement,
            'codigoCliente' => $customer->customer_code, 'codigoMetodoPago' => $paymentMethod, 'numeroTarjeta' => null,
            'montoTotal' => $total,
            'montoTotalSujetoIva' => (int) $manual->document_sector_code === InvoiceDocumentSector::ZERO_RATE ? '0' : $total,
            'codigoMoneda' => $currency, 'tipoCambio' => '1.00', 'montoTotalMoneda' => $total,
            'montoGiftCard' => null, 'descuentoAdicional' => $discount,
            'codigoExcepcion' => null, 'cafc' => $manual->cafcRange->cafc_code,
            'leyenda' => 'Ley N° 453: El proveedor debe brindar atención sin discriminación.',
            'usuario' => Str::limit($actor->name ?: $actor->email, 50, ''),
            'codigoDocumentoSector' => $manual->document_sector_code,
        ], 'detalle' => array_map(function (array $line) use ($manual): array {
            $detail = [
                'actividadEconomica' => $line['economic_activity_code'], 'codigoProductoSin' => $line['siat_product_code'],
                'codigoProducto' => $line['internal_code'], 'descripcion' => $line['description'],
                'cantidad' => number_format((float) $line['quantity'], 5, '.', ''),
                'unidadMedida' => $line['measurement_unit_code'], 'precioUnitario' => number_format((float) $line['unit_price'], 5, '.', ''),
                'montoDescuento' => number_format((float) $line['discount_amount'], 5, '.', ''),
                'subTotal' => number_format((float) $line['subtotal_amount'], 5, '.', ''),
            ];

            if ((int) $manual->document_sector_code === InvoiceDocumentSector::PURCHASE_SALE) {
                $detail['numeroSerie'] = null;
                $detail['numeroImei'] = null;
            }

            return $detail;
        }, $lines)];
    }

    /** @return array{xml: string, gzip: string} */
    private function storeXml(int $companyId, string $cuf, string $xml, string $gzip): array
    {
        $base = "manual-invoices/$companyId/".now()->format('Y/m')."/$cuf";
        foreach ([$base.'.xml' => $xml, $base.'.xml.gz' => $gzip] as $path => $contents) {
            if (Storage::disk('local')->exists($path) && ! hash_equals(hash('sha256', Storage::disk('local')->get($path)), hash('sha256', $contents))) {
                throw new RuntimeException('Existe un artefacto fiscal diferente en la ruta inmutable.');
            }
            Storage::disk('local')->put($path, $contents);
        }

        return ['xml' => $base.'.xml', 'gzip' => $base.'.xml.gz'];
    }

    /** @return array{path: string, hash: string} */
    private function storeEvidence(SinManualContingencyInvoice $manual, UploadedFile $file): array
    {
        $contents = $file->getContent();
        $hash = hash('sha256', $contents);
        $extension = strtolower($file->extension() ?: 'bin');
        $path = "manual-invoices/{$manual->company_id}/evidence/{$manual->id}-$hash.$extension";
        Storage::disk('local')->put($path, $contents);

        return ['path' => $path, 'hash' => $hash];
    }

    private function pointOfSale(int $companyId, int $branchId, int $pointId): SinPointOfSale
    {
        return SinPointOfSale::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('sin_branch_id', $branchId)->findOrFail($pointId);
    }
}
