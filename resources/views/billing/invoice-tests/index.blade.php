@extends('layouts.admin')

@section('title', 'Pruebas de facturación | '.config('app.name'))
@section('page-title', 'Pruebas de facturación')
@section('page-subtitle', 'Emisión secuencial controlada en el ambiente Piloto del SIN')

@section('content')
    <div class="invoice-test-shell">
        <section class="card invoice-test-command mb-3" aria-labelledby="invoice-test-title">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row align-items-lg-start justify-content-between gap-3 mb-4">
                    <div>
                        <span class="badge bg-azure-lt mb-2">Laboratorio SIAT · Piloto</span>
                        <h2 class="h3 mb-1" id="invoice-test-title">Cola secuencial de emisión</h2>
                        <p class="text-secondary mb-0">Cada documento espera la respuesta del anterior. Nunca se emiten dos facturas de este lote al mismo tiempo.</p>
                    </div>
                    <div class="invoice-test-limit" aria-label="Límite del lote">
                        <strong>500</strong>
                        <span>máximo por paquete</span>
                    </div>
                </div>

                @unless($pilotEnabled)
                    <div class="alert alert-danger" role="alert">
                        <div class="d-flex gap-2">
                            <i class="ti ti-lock" aria-hidden="true"></i>
                            <div><strong>Módulo bloqueado.</strong> Solo funciona cuando la autorización SIAT está configurada en Pruebas y Piloto.</div>
                        </div>
                    </div>
                @endunless

                <form method="POST" action="{{ route('billing.invoice-tests.store') }}" class="row g-3" data-invoice-test-form>
                    @csrf
                    <div class="col-12">
                        <fieldset>
                            <legend class="form-label">Modalidad de prueba</legend>
                            <div class="invoice-test-sector-options" role="radiogroup">
                                @foreach(\App\Enums\InvoiceTestMode::cases() as $mode)
                                    <label class="invoice-test-sector-option">
                                        <input class="form-check-input" type="radio" name="test_mode" value="{{ $mode->value }}" @checked(old('test_mode', \App\Enums\InvoiceTestMode::Online->value) === $mode->value) required @disabled(! $pilotEnabled) data-test-mode>
                                        <span><strong>{{ $mode->label() }}</strong><small>{{ $mode === \App\Enums\InvoiceTestMode::OfflineContingency ? 'Hasta 10 ciclos de 500 facturas: evento, paquete y validación' : 'Emisión secuencial contra SIAT' }}</small></span>
                                    </label>
                                @endforeach
                            </div>
                            @error('test_mode')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </fieldset>
                    </div>
                    <div class="col-12">
                        <fieldset>
                            <legend class="form-label">Tipo de facturación</legend>
                            <div class="invoice-test-sector-options" role="radiogroup" aria-describedby="test-sector-help">
                                @foreach($supportedSectors as $sectorCode => $sectorTitle)
                                    <label class="invoice-test-sector-option">
                                        <input class="form-check-input" type="radio" name="document_sector_code" value="{{ $sectorCode }}" @checked((int) old('document_sector_code', 1) === $sectorCode) required @disabled(! $pilotEnabled) data-test-sector>
                                        <span><strong>{{ $sectorTitle }}</strong><small>Sector {{ $sectorCode }} · Documento fiscal implementado</small></span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="text-secondary small mt-1" id="test-sector-help">La actividad y el producto deben estar habilitados por el SIN para el sector seleccionado.</div>
                            @error('document_sector_code')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
                        </fieldset>
                    </div>
                    <div class="col-12" data-contingency-fields hidden>
                        <div class="alert alert-azure mb-3"><strong>Secuencia controlada:</strong> cada ciclo esperará la validación final de su paquete. El SIN admite paquetes de hasta 500 facturas.</div>
                        <div class="row g-3">
                            <div class="col-12 col-lg-5">
                                <label class="form-label" for="test-event">Evento significativo</label>
                                <select class="form-select @error('event_code') is-invalid @enderror" id="test-event" name="event_code" data-contingency-input @disabled(! $pilotEnabled)>
                                    <option value="">Seleccionar</option>
                                    @foreach($significantEvents as $event)<option value="{{ $event->classifier_code }}" data-description="{{ $event->description }}" @selected(old('event_code') == $event->classifier_code)>{{ $event->classifier_code }} · {{ $event->description }}</option>@endforeach
                                </select>
                                @error('event_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12 col-lg-7">
                                <label class="form-label" for="test-event-description">Descripción oficial del SIN</label>
                                <input class="form-control @error('event_description') is-invalid @enderror" id="test-event-description" name="event_description" maxlength="500" value="{{ old('event_description') }}" data-contingency-input readonly @disabled(! $pilotEnabled)>
                                @error('event_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label" for="test-branch">Sucursal</label>
                        <select class="form-select @error('sin_branch_id') is-invalid @enderror" id="test-branch" name="sin_branch_id" required @disabled(! $pilotEnabled) data-test-branch>
                            <option value="">Seleccionar</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('sin_branch_id') == $branch->id)>{{ $branch->display_name }}</option>
                            @endforeach
                        </select>
                        @error('sin_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label" for="test-point">Punto de venta</label>
                        <select class="form-select @error('sin_point_of_sale_id') is-invalid @enderror" id="test-point" name="sin_point_of_sale_id" required @disabled(! $pilotEnabled) data-test-point>
                            <option value="">Seleccionar sucursal primero</option>
                            @foreach($branches as $branch)
                                @foreach($branch->activePointsOfSale as $point)
                                    <option value="{{ $point->id }}" data-branch-id="{{ $branch->id }}" @selected(old('sin_point_of_sale_id') == $point->id)>{{ $point->display_name }}</option>
                                @endforeach
                            @endforeach
                        </select>
                        @error('sin_point_of_sale_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label" for="test-activity">Actividad económica</label>
                        <select class="form-select @error('economic_activity_code') is-invalid @enderror" id="test-activity" name="economic_activity_code" required @disabled(! $pilotEnabled) data-test-activity>
                            <option value="">Seleccionar</option>
                            @foreach($activities as $activity)
                                @php
                                    $activityCode = data_get($activity->raw_data, 'codigoCaeb', $activity->classifier_code);
                                @endphp
                                <option value="{{ $activityCode }}" data-zero-rate="{{ $zeroRateActivityCodes->contains((string) $activityCode) ? 'true' : 'false' }}" @selected(old('economic_activity_code') == $activityCode)>{{ $activityCode }} · {{ $activity->description }}</option>
                            @endforeach
                        </select>
                        @error('economic_activity_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="test-customer">Cliente de prueba</label>
                        <select class="form-select @error('customer_id') is-invalid @enderror" id="test-customer" name="customer_id" required @disabled(! $pilotEnabled)>
                            <option value="">Seleccionar</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->name }} · {{ $customer->document_number }}</option>
                            @endforeach
                        </select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="test-product">Producto o servicio</label>
                        <select class="form-select @error('product_id') is-invalid @enderror" id="test-product" name="product_id" required @disabled(! $pilotEnabled) data-test-product>
                            <option value="">Seleccionar</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" data-price="{{ $product->unit_price }}" data-activity-code="{{ $product->economic_activity_code }}" @selected(old('product_id') == $product->id)>{{ $product->internal_code }} · {{ $product->description }}</option>
                            @endforeach
                        </select>
                        @error('product_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="test-quantity">Cantidad por factura</label>
                        <input class="form-control @error('quantity') is-invalid @enderror" id="test-quantity" name="quantity" type="number" min="0.00001" step="0.00001" value="{{ old('quantity', 1) }}" required @disabled(! $pilotEnabled)>
                        @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="test-price">Precio unitario</label>
                        <input class="form-control @error('unit_price') is-invalid @enderror" id="test-price" name="unit_price" type="number" min="0" step="0.00001" value="{{ old('unit_price') }}" required @disabled(! $pilotEnabled) data-test-price>
                        @error('unit_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="test-payment">Método de pago</label>
                        <select class="form-select @error('payment_method_code') is-invalid @enderror" id="test-payment" name="payment_method_code" required @disabled(! $pilotEnabled)>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method->classifier_code }}" @selected(old('payment_method_code', 1) == $method->classifier_code)>{{ $method->classifier_code }} · {{ $method->description }}</option>
                            @endforeach
                        </select>
                        @error('payment_method_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="test-currency">Moneda</label>
                        <select class="form-select @error('currency_code') is-invalid @enderror" id="test-currency" name="currency_code" required @disabled(! $pilotEnabled)>
                            @foreach($currencies as $currency)
                                <option value="{{ $currency->classifier_code }}" @selected(old('currency_code', 1) == $currency->classifier_code)>{{ $currency->classifier_code }} · {{ $currency->description }}</option>
                            @endforeach
                        </select>
                        @error('currency_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <div class="invoice-test-launch">
                            <div class="invoice-test-launch-field">
                                <label class="form-label mb-1" for="test-count" data-count-label>Número de facturas</label>
                                <div class="text-secondary small" data-count-help>Entre 1 y 500. La prueba se detiene de forma natural al terminar la cola.</div>
                                <input class="form-control form-control-lg @error('invoice_count') is-invalid @enderror" id="test-count" name="invoice_count" type="number" min="1" max="500" value="{{ old('invoice_count', 1) }}" required @disabled(! $pilotEnabled)>
                                @error('invoice_count')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="invoice-test-launch-field" data-contingency-count-field hidden>
                                <label class="form-label mb-1 required" for="test-invoices-per-cycle">Facturas por ciclo</label>
                                <div class="text-secondary small" data-invoices-per-cycle-help>Cada ciclo puede generar de 1 a 500 facturas.</div>
                                <input class="form-control form-control-lg @error('invoices_per_cycle') is-invalid @enderror" id="test-invoices-per-cycle" name="invoices_per_cycle" type="number" min="1" max="500" value="{{ old('invoices_per_cycle', 1) }}" data-contingency-input data-invoices-per-cycle @disabled(! $pilotEnabled)>
                                @error('invoices_per_cycle')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button class="btn btn-primary btn-lg" type="submit" @disabled(! $pilotEnabled)>
                                <i class="ti ti-player-play me-1" aria-hidden="true"></i>Iniciar emisión secuencial
                            </button>
                        </div>
                        @error('environment')<div class="text-danger small mt-2" role="alert">{{ $message }}</div>@enderror
                    </div>
                </form>
            </div>
        </section>

        @if($selectedBatch)
            @php
                $progress = $selectedBatch->requested_count > 0 ? (int) round(($selectedBatch->processed_count / $selectedBatch->requested_count) * 100) : 0;
                $active = $selectedBatch->batch_status->isActive() || $selectedBatch->cancellation_status?->isActive() || $selectedBatch->reversal_status?->isActive();
                $cancellableCount = $selectedBatch->items->filter(fn ($item) =>
                    $item->item_status === \App\Enums\InvoiceTestItemStatus::Succeeded
                    && in_array($item->invoice?->fiscal_status, [\App\Enums\InvoiceFiscalStatus::Validated, \App\Enums\InvoiceFiscalStatus::ValidatedAfterContingency], true)
                )->count();
                $reversibleCount = $selectedBatch->items->filter(fn ($item) =>
                    $item->cancellation_status === \App\Enums\InvoiceTestItemStatus::Succeeded
                    && $item->invoice?->fiscal_status === \App\Enums\InvoiceFiscalStatus::CancelledInSiat
                    && $item->invoice?->cancellation_status_code === 905
                    && ! $item->invoice?->reversed_at
                    && filled($selectedBatch->customer->email)
                    && now()->lte($item->invoice?->issued_at?->startOfMonth()->addMonth()->day(9)->endOfDay())
                )->count();
            @endphp
            <section class="card mb-3" aria-labelledby="batch-progress-title" @if($active) data-active-test-batch @endif>
                <div class="card-header align-items-start">
                    <div>
                        <h2 class="card-title" id="batch-progress-title">Lote #{{ $selectedBatch->id }} · {{ $selectedBatch->batch_status->label() }}</h2>
                        <div class="text-secondary small">{{ $selectedBatch->test_mode->label() }}@if($selectedBatch->test_mode === \App\Enums\InvoiceTestMode::OfflineContingency) · {{ $selectedBatch->invoices_per_cycle }} factura(s) por ciclo @endif · {{ \App\Services\Billing\InvoiceDocumentSector::title($selectedBatch->document_sector_code) }} · {{ $selectedBatch->product->description }} para {{ $selectedBatch->customer->name }}</div>
                    </div>
                    <div class="ms-auto text-end"><strong>{{ $selectedBatch->processed_count }}/{{ $selectedBatch->requested_count }}</strong><div class="text-secondary small">procesadas</div></div>
                </div>
                <div class="card-body">
                    <div class="progress progress-lg mb-3" role="progressbar" aria-label="Avance del lote" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar {{ $selectedBatch->failed_count ? 'bg-warning' : 'bg-primary' }}" style="width: {{ $progress }}%">{{ $progress }}%</div>
                    </div>
                    <div class="row g-2 mb-3 text-center">
                        <div class="col-4"><div class="invoice-test-stat"><strong>{{ $selectedBatch->successful_count }}</strong><span>Emitidas</span></div></div>
                        <div class="col-4"><div class="invoice-test-stat"><strong>{{ $selectedBatch->failed_count }}</strong><span>Fallidas</span></div></div>
                        <div class="col-4"><div class="invoice-test-stat"><strong>{{ $selectedBatch->requested_count - $selectedBatch->processed_count }}</strong><span>En espera</span></div></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter">
                            <thead><tr><th>Secuencia</th><th>Emisión</th><th>Etapa</th><th>Factura</th><th>Evento</th><th>Paquete</th><th>Resultado</th><th>Anulación</th><th>Resultado anulación</th><th>Reversión</th><th>Resultado reversión</th><th>Hora Bolivia</th></tr></thead>
                            <tbody>
                                @foreach($selectedBatch->items as $item)
                                    <tr>
                                        <td><span class="invoice-test-sequence">{{ str_pad((string) $item->position, 2, '0', STR_PAD_LEFT) }}</span></td>
                                        <td><span class="badge {{ $item->item_status === \App\Enums\InvoiceTestItemStatus::Succeeded ? 'bg-success-lt' : ($item->item_status === \App\Enums\InvoiceTestItemStatus::Failed ? 'bg-danger-lt' : ($item->item_status === \App\Enums\InvoiceTestItemStatus::Running ? 'bg-blue-lt' : 'bg-secondary-lt')) }}">{{ $item->item_status->label() }}</span></td>
                                        <td class="small">{{ str($item->stage)->replace('_', ' ')->title() }}</td>
                                        <td>@if($item->invoice)<a href="{{ route('billing.invoices.print', $item->invoice) }}" target="_blank" rel="noopener">N.º {{ $item->invoice->invoice_number }}</a>@else — @endif</td>
                                        <td class="small">@if($item->significantEvent)#{{ $item->significantEvent->id }} · {{ $item->significantEvent->event_status->label() }}<br><span class="text-secondary">{{ $item->significantEvent->reception_code ?: 'Sin recepción' }}</span>@else — @endif</td>
                                        <td class="small">@if($item->invoicePackage)#{{ $item->invoicePackage->id }} · {{ $item->invoicePackage->package_status->label() }}<br><span class="text-secondary">{{ $item->invoicePackage->reception_code ?: 'Sin recepción' }}</span>@else — @endif</td>
                                        <td class="text-secondary small">{{ $item->message ?? 'Esperando turno' }}</td>
                                        <td>@if($item->cancellation_status)<span class="badge {{ $item->cancellation_status === \App\Enums\InvoiceTestItemStatus::Succeeded ? 'bg-success-lt' : ($item->cancellation_status === \App\Enums\InvoiceTestItemStatus::Failed ? 'bg-danger-lt' : 'bg-blue-lt') }}">{{ $item->cancellation_status->label() }}</span>@else — @endif</td>
                                        <td class="text-secondary small">{{ $item->cancellation_message ?? '—' }}</td>
                                        <td>@if($item->reversal_status)<span class="badge {{ $item->reversal_status === \App\Enums\InvoiceTestItemStatus::Succeeded ? 'bg-success-lt' : ($item->reversal_status === \App\Enums\InvoiceTestItemStatus::Failed ? 'bg-danger-lt' : 'bg-blue-lt') }}">{{ match($item->reversal_status) { \App\Enums\InvoiceTestItemStatus::Pending => 'En espera', \App\Enums\InvoiceTestItemStatus::Running => 'Revirtiendo', \App\Enums\InvoiceTestItemStatus::Succeeded => 'Revertida', \App\Enums\InvoiceTestItemStatus::Failed => 'Fallida' } }}</span>@else — @endif</td>
                                        <td class="text-secondary small">{{ $item->reversal_message ?? '—' }}</td>
                                        <td class="text-nowrap">{{ $item->reversal_finished_at?->format('H:i:s') ?? $item->cancellation_finished_at?->format('H:i:s') ?? $item->finished_at?->format('H:i:s') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="invoice-test-cancellation mt-3">
                        @if($selectedBatch->cancellation_status)
                            <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-center">
                                <div><strong>Anulación: {{ $selectedBatch->cancellation_status->label() }}</strong><div class="text-secondary small">{{ $selectedBatch->cancellation_processed_count }}/{{ $selectedBatch->cancellation_requested_count }} procesadas · {{ $selectedBatch->cancellation_successful_count }} anuladas · {{ $selectedBatch->cancellation_failed_count }} fallidas</div></div>
                                @if($cancellableCount > 0 && ! $selectedBatch->cancellation_status->isActive())
                                    <span class="badge bg-warning-lt">Quedan {{ $cancellableCount }} facturas validadas</span>
                                @endif
                            </div>
                        @endif
                        @if($cancellableCount > 0 && ! $selectedBatch->batch_status->isActive() && ! $selectedBatch->cancellation_status?->isActive())
                            <form method="POST" action="{{ route('billing.invoice-tests.cancel', $selectedBatch) }}" class="d-flex flex-column flex-md-row gap-3 align-items-md-end mt-3" data-confirm-action data-confirm-title="¿Anular {{ $cancellableCount }} facturas del lote?" data-confirm-text="Las solicitudes se enviarán una por una al SIN. Esta es una operación fiscal real en el ambiente Piloto." data-confirm-button="Sí, iniciar anulaciones">
                                @csrf
                                <div class="flex-fill"><label class="form-label" for="test-cancellation-reason">Motivo oficial de anulación</label><select class="form-select @error('reason_code') is-invalid @enderror" id="test-cancellation-reason" name="reason_code" required><option value="">Seleccionar motivo</option>@foreach($cancellationReasons as $reason)<option value="{{ $reason->classifier_code }}">{{ $reason->classifier_code }} · {{ $reason->description }}</option>@endforeach</select>@error('reason_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <button type="submit" class="btn btn-danger"><i class="ti ti-file-x me-1" aria-hidden="true"></i>Anular {{ $cancellableCount }} facturas en secuencia</button>
                            </form>
                        @elseif(! $selectedBatch->cancellation_status)
                            <div class="text-secondary small"><i class="ti ti-info-circle me-1" aria-hidden="true"></i>La prueba de anulación se habilitará cuando el lote tenga facturas validadas en el SIN.</div>
                        @endif
                        @error('cancellation')<div class="alert alert-danger mt-2 mb-0">{{ $message }}</div>@enderror
                    </div>
                    <div class="invoice-test-reversal border-top mt-4 pt-3">
                        @if($selectedBatch->reversal_status)
                            <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-center">
                                <div><strong>Reversión: {{ $selectedBatch->reversal_status->label() }}</strong><div class="text-secondary small">{{ $selectedBatch->reversal_processed_count }}/{{ $selectedBatch->reversal_requested_count }} procesadas · {{ $selectedBatch->reversal_successful_count }} revertidas · {{ $selectedBatch->reversal_failed_count }} fallidas</div></div>
                            </div>
                        @endif
                        @if($reversibleCount > 0 && ! $selectedBatch->batch_status->isActive() && ! $selectedBatch->cancellation_status?->isActive() && ! $selectedBatch->reversal_status?->isActive())
                            <form method="POST" action="{{ route('billing.invoice-tests.reverse', $selectedBatch) }}" class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-center mt-3" data-confirm-action data-confirm-title="¿Revertir {{ $reversibleCount }} anulaciones del lote?" data-confirm-text="Las solicitudes se enviarán una por una al SIN. Las facturas volverán a quedar válidas y no podrán anularse nuevamente." data-confirm-button="Sí, iniciar reversiones">
                                @csrf
                                <div><strong>Reversión de anulaciones</strong><div class="text-secondary small">Disponibles: {{ $reversibleCount }} facturas anuladas conforme con código 905.</div></div>
                                <button type="submit" class="btn btn-warning"><i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>Revertir {{ $reversibleCount }} facturas en secuencia</button>
                            </form>
                        @elseif(! $selectedBatch->reversal_status)
                            <div class="text-secondary small"><i class="ti ti-info-circle me-1" aria-hidden="true"></i>La reversión se habilitará cuando el lote tenga facturas anuladas conforme, dentro del plazo y con correo del comprador.</div>
                        @endif
                        @error('reversal')<div class="alert alert-danger mt-2 mb-0">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>
        @endif

        <section class="card" aria-labelledby="batch-history-title">
            <div class="card-header"><h2 class="card-title" id="batch-history-title">Historial de pruebas</h2></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th>Lote</th><th>Creado</th><th>Configuración</th><th>Avance</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                        @forelse($batches as $batch)
                            <tr>
                                <td class="fw-semibold">#{{ $batch->id }}</td>
                                <td>{{ $batch->created_at->format('d/m/Y H:i:s') }}</td>
                                <td><div>{{ $batch->test_mode->label() }} · {{ \App\Services\Billing\InvoiceDocumentSector::title($batch->document_sector_code) }}</div><div class="text-secondary small">{{ $batch->pointOfSale->display_name }} · {{ $batch->customer->name }}</div></td>
                                <td>{{ $batch->processed_count }}/{{ $batch->requested_count }} <span class="text-success">· {{ $batch->successful_count }} emitidas</span></td>
                                <td>{{ $batch->batch_status->label() }}</td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="{{ route('billing.invoice-tests.index', ['batch' => $batch->id]) }}">Ver detalle</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-secondary py-5">Todavía no existen lotes de prueba.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($batches->hasPages())<div class="card-footer">{{ $batches->links() }}</div>@endif
        </section>
    </div>
@endsection

@push('styles')
<style>
.invoice-test-sector-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}.invoice-test-sector-option{display:flex;gap:.75rem;align-items:flex-start;padding:.85rem 1rem;border:1px solid var(--test-line);border-radius:.75rem;cursor:pointer;background:#fff}.invoice-test-sector-option:has(input:checked){border-color:var(--tblr-primary);box-shadow:0 0 0 1px var(--tblr-primary);background:var(--tblr-primary-lt)}.invoice-test-sector-option input{margin-top:.2rem}.invoice-test-sector-option span{display:grid}.invoice-test-sector-option small{color:var(--tblr-secondary)}
.invoice-test-shell{--test-ink:#1e3a5f;--test-line:#dbe4ef}.invoice-test-command{border-top:3px solid var(--test-ink)}.invoice-test-limit{display:grid;min-width:9rem;padding:.65rem 1rem;border:1px solid var(--test-line);border-radius:.75rem;text-align:right;background:#f8fafc}.invoice-test-limit strong{font:600 2rem/1 var(--tblr-font-monospace);color:var(--test-ink)}.invoice-test-limit span{font-size:.75rem;color:var(--tblr-secondary)}.invoice-test-launch{display:grid;grid-template-columns:minmax(12rem,1fr) minmax(12rem,1fr) auto;gap:1rem;align-items:end;padding:1rem;border:1px solid var(--test-line);border-radius:.75rem;background:#f8fafc}.invoice-test-launch:has([data-contingency-count-field][hidden]){grid-template-columns:minmax(12rem,1fr) auto}.invoice-test-launch-field{display:grid;align-content:end}.invoice-test-launch-field .small{min-height:2.25rem}.invoice-test-stat{display:grid;padding:.75rem;border:1px solid var(--test-line);border-radius:.65rem}.invoice-test-stat strong{font:600 1.4rem/1.2 var(--tblr-font-monospace);color:var(--test-ink)}.invoice-test-stat span{font-size:.75rem;color:var(--tblr-secondary)}.invoice-test-sequence{display:inline-grid;place-items:center;width:2.25rem;height:2.25rem;border:1px solid var(--test-line);border-radius:50%;font-family:var(--tblr-font-monospace);font-weight:600;color:var(--test-ink)}@media(max-width:767.98px){.invoice-test-sector-options{grid-template-columns:1fr}.invoice-test-launch,.invoice-test-launch:has([data-contingency-count-field][hidden]){grid-template-columns:1fr}.invoice-test-launch-field .small{min-height:0}.invoice-test-limit{text-align:left}.invoice-test-launch .btn{min-height:44px}}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const branch = document.querySelector('[data-test-branch]');
    const point = document.querySelector('[data-test-point]');
    const activity = document.querySelector('[data-test-activity]');
    const product = document.querySelector('[data-test-product]');
    const price = document.querySelector('[data-test-price]');
    const sectors = [...document.querySelectorAll('[data-test-sector]')];
    const modes = [...document.querySelectorAll('[data-test-mode]')];
    const contingencyFields = document.querySelector('[data-contingency-fields]');
    const contingencyInputs = [...document.querySelectorAll('[data-contingency-input]')];
    const count = document.querySelector('#test-count');
    const countHelp = document.querySelector('[data-count-help]');
    const countLabel = document.querySelector('[data-count-label]');
    const contingencyCountField = document.querySelector('[data-contingency-count-field]');
    const invoicesPerCycle = document.querySelector('[data-invoices-per-cycle]');
    const invoicesPerCycleHelp = document.querySelector('[data-invoices-per-cycle-help]');
    const eventSelect = document.querySelector('#test-event');
    const eventDescription = document.querySelector('#test-event-description');

    const filterOptions = (select, attribute, value, placeholder) => {
        if (!select) return;
        [...select.options].forEach((option, index) => {
            if (index === 0) return;
            option.hidden = !value || option.dataset[attribute] !== value;
        });
        const selected = select.options[select.selectedIndex];
        if (selected && selected.hidden) select.value = '';
        select.options[0].textContent = value ? placeholder : 'Selecciona la opción anterior';
    };

    const filterPoints = () => filterOptions(point, 'branchId', branch?.value ?? '', 'Seleccionar punto de venta');
    const filterProducts = () => filterOptions(product, 'activityCode', activity?.value ?? '', 'Seleccionar producto o servicio');
    const filterActivities = () => {
        if (!activity) return;
        const zeroRate = sectors.find((sector) => sector.checked)?.value === '8';
        [...activity.options].forEach((option, index) => {
            if (index === 0) return;
            option.hidden = zeroRate && option.dataset.zeroRate !== 'true';
        });
        if (activity.options[activity.selectedIndex]?.hidden) activity.value = '';
        filterProducts();
    };
    branch?.addEventListener('change', filterPoints);
    activity?.addEventListener('change', filterProducts);
    sectors.forEach((sector) => sector.addEventListener('change', filterActivities));
    const toggleMode = () => {
        const offline = modes.find((mode) => mode.checked)?.value === 'OFFLINE_CONTINGENCY';
        if (contingencyFields) contingencyFields.hidden = !offline;
        if (contingencyCountField) contingencyCountField.hidden = !offline;
        contingencyInputs.forEach((input) => input.required = offline);
        if (count) {
            count.readOnly = false;
            if (offline && Number(count.value) > 10) count.value = '10';
            count.max = offline ? '10' : '500';
        }
        if (countHelp) countHelp.textContent = offline ? 'Entre 1 y 10 ciclos, procesados secuencialmente.' : 'Entre 1 y 500 facturas en línea.';
        if (countLabel) countLabel.textContent = offline ? 'Número de ciclos' : 'Número de facturas';
        if (invoicesPerCycle) {
            invoicesPerCycle.readOnly = false;
            if (invoicesPerCycleHelp) invoicesPerCycleHelp.textContent = 'Cada ciclo genera un paquete de 1 a 500 facturas.';
        }
    };
    modes.forEach((mode) => mode.addEventListener('change', toggleMode));
    count?.addEventListener('input', toggleMode);
    const syncEventDescription = () => {
        if (!eventDescription || !eventSelect) return;
        eventDescription.value = eventSelect.options[eventSelect.selectedIndex]?.dataset.description ?? '';
    };
    eventSelect?.addEventListener('change', syncEventDescription);
    filterPoints();
    filterActivities();
    toggleMode();
    syncEventDescription();

    product?.addEventListener('change', () => {
        const option = product.options[product.selectedIndex];
        if (price && option?.dataset.price) price.value = option.dataset.price;
    });
    if (document.querySelector('[data-active-test-batch]')) {
        window.setTimeout(() => window.location.reload(), 3000);
    }
});
</script>
@endpush
