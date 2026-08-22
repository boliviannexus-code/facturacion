@extends('layouts.admin')

@section('title', $invoiceTitle.' | '.config('app.name', 'Base Admin'))
@section('page-title', 'Emisión de '.$invoiceTitle)
@section('page-subtitle', 'Codigo documento sector '.$sector->classifier_code)

@section('content')
    @php
        $taxId = $authorization?->tax_id ?? $company?->tax_id ?? '-';
        $legalName = $authorization?->legal_name ?? $company?->legal_name ?? $company?->name ?? '-';
        $defaultCurrencyCode = old('currency_code', '1');
    @endphp

    <form
        class="invoice-workspace"
        method="POST"
        action="{{ isset($manualCafc) ? route('billing.manual-cafc.transcribe.update', $manualCafc) : route('billing.invoices.issue.purchase-sale.store') }}"
        autocomplete="off"
        novalidate
        data-invoice-issue-form
        @isset($manualCafc) data-manual-cafc="1" data-preserve-issued-at="1" @endisset
    >
        @csrf
        @isset($manualCafc) @method('PUT') @endisset
        <input name="issuance_key" type="hidden" value="{{ $issuanceKey }}">
        <input name="document_sector_code" type="hidden" value="{{ $documentSectorCode }}">

        <header class="invoice-ribbon">
            <div>
                <div class="invoice-ribbon-kicker">{{ isset($manualCafc) ? 'Transcripción CAFC · '.$invoiceTitle : $invoiceTitle }}</div>
                <h2 class="invoice-ribbon-title">{{ $legalName }}</h2>
            </div>
            <div
                class="invoice-ribbon-meta"
                data-invoice-fiscal-status
                data-communication-ok="{{ $communicationStatus['ok'] ? '1' : '0' }}"
                data-cufd-request-url="{{ route('billing.invoices.issue.cufd.request') }}"
                data-refresh-cufd-on-selection="{{ $refreshCufdOnPointOfSaleSelection ? '1' : '0' }}"
            >
                <span class="invoice-status-pill {{ $communicationStatus['ok'] ? 'is-ok' : 'is-bad' }}">
                    <span>
                        <strong>{{ $taxId }}</strong>
                        <small @class(['d-none' => $communicationStatus['ok']])>
                            {{ $communicationStatus['invalid_detail'] }}
                        </small>
                    </span>
                </span>
                <span class="invoice-status-pill is-bad" data-cuis-status>
                    <span>
                        <strong data-status-label>CUIS</strong>
                        <small data-status-detail>CUIS no vigente</small>
                    </span>
                </span>
                <span class="invoice-status-pill is-bad" data-cufd-status>
                    <span>
                        <strong data-status-label>CUFD</strong>
                        <small data-status-detail>CUFD no vigente</small>
                    </span>
                </span>
            </div>
        </header>

        @isset($manualCafc)
            <div class="alert alert-info" role="status">
                <strong>CAFC {{ $manualCafc->cafcRange->cafc_code }} · Factura N.º {{ $manualCafc->manual_invoice_number }}</strong>
                <span class="ms-2">Evento {{ $manualCafc->significantEvent?->event_code }} · {{ $manualCafc->significantEvent?->event_description }} · Fecha original {{ $manualCafc->issued_manually_at->format('d/m/Y H:i:s') }}</span>
            </div>
        @endisset

        @if ($authorization?->force_offline_emission && ! isset($manualCafc))
            <div class="alert alert-warning" role="status">
                <i class="ti ti-wifi-off me-1" aria-hidden="true"></i>
                <strong>Emisión fuera de línea activada.</strong>
                La factura se guardará localmente y quedará pendiente de regularización. Puedes cambiar este modo en Parámetros → Autorización.
            </div>
        @endif

        <div class="alert {{ $communicationStatus['ok'] ? 'alert-warning d-none' : 'alert-danger' }}" role="alert" data-invoice-communication-message>
            {{ $communicationStatus['ok']
                ? 'Existe una contingencia pendiente. Las nuevas facturas continuaran emitiendose fuera de linea hasta registrar el evento significativo.'
                : 'No existe comunicacion con SIAT. Las facturas se emitiran fuera de linea y quedaran pendientes de sincronizacion.' }}
        </div>

        <div class="invoice-flow-top">
                <section class="invoice-panel" aria-labelledby="invoice-transaction-heading">
                    <div class="invoice-panel-header">
                        <span class="invoice-panel-icon invoice-panel-icon-blue" aria-hidden="true"><i class="ti ti-arrows-exchange"></i></span>
                        <div>
                            <h3 id="invoice-transaction-heading">Datos de la transaccion comercial</h3>
                            <p>Sucursal, actividad economica y documento fiscal</p>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label class="form-label" for="invoice-point-of-sale">Sucursal / punto de venta</label>
                            @isset($manualCafc)
                                <input type="hidden" name="sin_point_of_sale_id" value="{{ $manualCafc->sin_point_of_sale_id }}">
                            @endisset
                            <select class="form-select" id="invoice-point-of-sale" @unless(isset($manualCafc)) name="sin_point_of_sale_id" @endunless data-tom-select data-allow-empty-option="false" data-placeholder="Buscar sucursal o PV" data-invoice-point-of-sale @disabled(isset($manualCafc))>
                                <option value="" disabled selected>Seleccionar sucursal / PV</option>
                                @foreach ($branches as $branch)
                                    @forelse ($branch->activePointsOfSale as $point)
                                        @php($status = $fiscalStatuses[(string) $point->id] ?? null)
                                        <option
                                            value="{{ $point->id }}"
                                            @selected(isset($manualCafc) && $manualCafc->sin_point_of_sale_id === $point->id)
                                            data-cuis-valid="{{ ($status['cuis_valid'] ?? false) ? '1' : '0' }}"
                                            data-cuis-label="{{ $status['cuis_label'] ?? 'CUIS' }}"
                                            data-cuis-detail="{{ $status['cuis_detail'] ?? 'CUIS no vigente' }}"
                                            data-cufd-valid="{{ ($status['cufd_valid'] ?? false) ? '1' : '0' }}"
                                            data-cufd-label="{{ $status['cufd_label'] ?? 'CUFD' }}"
                                            data-cufd-detail="{{ $status['cufd_detail'] ?? 'CUFD no vigente' }}"
                                            data-recovery-blocked="{{ ($status['recovery_blocked'] ?? false) ? '1' : '0' }}"
                                        >
                                            Suc. {{ $branch->branch_code }} - {{ $branch->name }} / PV {{ $point->point_of_sale_code }} - {{ $point->name }}
                                        </option>
                                    @empty
                                        <option
                                            value="branch-{{ $branch->id }}"
                                            disabled
                                            data-cuis-valid="0"
                                            data-cuis-label="CUIS"
                                            data-cuis-detail="CUIS no vigente"
                                            data-cufd-valid="0"
                                            data-cufd-label="CUFD"
                                            data-cufd-detail="CUFD no vigente"
                                        >
                                            Suc. {{ $branch->branch_code }} - {{ $branch->name }}
                                        </option>
                                    @endforelse
                                @endforeach
                            </select>
                        </div>

                        <div class="col-lg-6">
                            <label class="form-label" for="invoice-activity">Actividad</label>
                            <select class="form-select" id="invoice-activity" name="economic_activity_code" data-tom-select data-allow-empty-option="false" data-placeholder="Buscar actividad">
                                <option value="" disabled selected>Seleccionar actividad</option>
                                @foreach ($activities as $activity)
                                    @php($activityCode = data_get($activity->raw_data, 'codigoCaeb', $activity->classifier_code))
                                    <option value="{{ $activityCode }}">{{ $activityCode }} - {{ $activity->description }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </section>

                <section class="invoice-client-strip" aria-labelledby="invoice-client-heading">
                    <div class="invoice-client-heading">
                        <div>
                            <h3 id="invoice-client-heading">Datos básicos del cliente</h3>
                            <p>Busca un cliente registrado o crea uno nuevo</p>
                        </div>
                    </div>
                    <div class="row g-3 align-items-end invoice-client-picker">
                        <div class="col-lg-8">
                            <label class="form-label" for="invoice-customer-id">Cliente registrado</label>
                            <select class="form-select" id="invoice-customer-id" name="customer_id" data-tom-select data-allow-empty-option="false" data-placeholder="Buscar cliente" data-invoice-customer-select>
                                <option value="" disabled selected>Seleccionar cliente</option>
                                @foreach ($customers as $customer)
                                    <option
                                        value="{{ $customer->id }}"
                                        data-name="{{ $customer->name }}"
                                        data-document="{{ $customer->document_number }}"
                                        data-complement="{{ $customer->document_complement }}"
                                        data-email="{{ $customer->email }}"
                                        data-customer-code="{{ $customer->customer_code }}"
                                        data-document-type="{{ $customer->identity_document_type_code }}"
                                    >
                                        {{ $customer->name }} - {{ $customer->document_number }}{{ $customer->document_complement ? '-'.$customer->document_complement : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-lg-4 d-flex align-items-end">
                            @can('customers.create')
                                <a
                                    class="btn btn-outline-primary w-100"
                                    href="{{ route('parameters.customers.create') }}"
                                    data-modal-url="{{ route('parameters.customers.create') }}"
                                    data-modal-title="Cliente nuevo"
                                    data-modal-size="xl"
                                >
                                    <i class="ti ti-user-plus me-1" aria-hidden="true"></i>Cliente nuevo
                                </a>
                            @else
                                <button class="btn btn-outline-secondary w-100" type="button" disabled>
                                    <i class="ti ti-user-plus me-1" aria-hidden="true"></i>Cliente nuevo
                                </button>
                            @endcan
                        </div>
                    </div>
                    <dl>
                        <div>
                            <dt>Razon social</dt>
                            <dd data-client-name>-</dd>
                        </div>
                        <div>
                            <dt>Numero NIT/CI</dt>
                            <dd data-client-document>-</dd>
                        </div>
                        <div>
                            <dt>Correo electronico</dt>
                            <dd data-client-email>-</dd>
                        </div>
                        <div>
                            <dt>Cod. complemento</dt>
                            <dd data-client-complement>-</dd>
                        </div>
                    </dl>
                </section>
        </div>

        <div class="invoice-layout">
            <div class="invoice-main">
                <section class="invoice-panel" aria-labelledby="invoice-detail-heading">
                    <div class="invoice-panel-header">
                        <span class="invoice-panel-icon invoice-panel-icon-green" aria-hidden="true"><i class="ti ti-shopping-cart"></i></span>
                        <div>
                            <h3 id="invoice-detail-heading">Detalle de la transaccion comercial</h3>
                            <p>Productos homologados con el catalogo SIAT</p>
                        </div>
                    </div>

                    <div class="row g-3 align-items-end invoice-item-entry">
                        <div class="col-lg-6 invoice-item-description">
                            <label class="form-label" for="invoice-product-id">Codigo / descripcion</label>
                            <select class="form-select" id="invoice-product-id" name="product_id" data-tom-select data-allow-empty-option="false" data-placeholder="Buscar producto" data-invoice-product-select>
                                <option value="" disabled selected>Seleccionar producto</option>
                                @foreach ($products as $product)
                                    <option
                                        value="{{ $product->id }}"
                                        data-internal-code="{{ $product->internal_code }}"
                                        data-description="{{ $product->description }}"
                                        data-unit-price="{{ $product->unit_price }}"
                                        data-unit-code="{{ $product->measurement_unit_code }}"
                                        data-unit-description="{{ $product->measurement_unit_description }}"
                                        data-activity-code="{{ $product->economic_activity_code }}"
                                        data-siat-product-code="{{ $product->siat_product_code }}"
                                    >
                                        {{ $product->internal_code }} - {{ $product->description }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-lg-2">
                            <label class="form-label" for="invoice-quantity">Cantidad</label>
                            <input class="form-control text-end" id="invoice-quantity" name="quantity" type="number" min="0.00001" step="0.00001" value="1.00" data-invoice-quantity>
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label">Unidad medida</label>
                            <div class="invoice-readonly-pill" data-product-unit>Seleccione un producto</div>
                        </div>

                        <div class="col-12 invoice-item-description">
                            <label class="form-label" for="invoice-additional-description">Descripcion adicional</label>
                            <textarea class="form-control" id="invoice-additional-description" name="additional_description" rows="3" maxlength="485" data-character-counter="#invoice-additional-count"></textarea>
                            <div class="form-hint" id="invoice-additional-count">0 caracteres</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="invoice-unit-price">Precio unitario</label>
                            <div class="input-group">
                                <span class="input-group-text">Bs</span>
                                <input class="form-control text-end" id="invoice-unit-price" name="unit_price" type="number" min="0" step="0.00001" data-invoice-unit-price>
                            </div>
                        </div>

                        <div class="col-md-5 invoice-item-discount">
                            <label class="form-label" for="invoice-discount">Descuento del ítem</label>
                            <div class="input-group invoice-discount-control">
                                <label class="visually-hidden" for="invoice-discount-type">Tipo de descuento</label>
                                <select class="form-select" id="invoice-discount-type" data-invoice-discount-type aria-label="Tipo de descuento">
                                    <option value="FIXED">Bs</option>
                                    <option value="PERCENTAGE">%</option>
                                </select>
                                <input class="form-control text-end" id="invoice-discount" name="discount" type="number" min="0" step="0.00001" value="0.00" data-invoice-discount>
                            </div>
                                  </div>

                        <div class="col-md-3 invoice-item-add">
                            <button class="btn btn-primary w-100" type="button" data-invoice-add-item aria-label="Adicionar producto al detalle de la factura">
                                <i class="ti ti-shopping-cart-plus me-1" aria-hidden="true"></i>Adicionar
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive mt-3">
                        <table class="table table-sm align-middle invoice-detail-table">
                            <thead>
                                <tr>
                                    <th>Codigo/descripcion</th>
                                    <th class="text-end">Cantidad</th>
                                    <th>Unidad medida</th>
                                    <th class="text-end">Precio unitario</th>
                                    <th class="text-end">Descuento</th>
                                    <th class="text-end">Subtotal</th>
                                    <th class="text-end">Accion</th>
                                </tr>
                            </thead>
                            <tbody data-invoice-items>
                                <tr data-invoice-empty>
                                    <td colspan="7" class="text-body-secondary">Ningun producto/servicio seleccionado</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <aside class="invoice-summary" aria-labelledby="invoice-summary-heading">
                <h3 id="invoice-summary-heading">Resumen</h3>

                <input id="invoice-issued-at" name="issued_at" type="hidden" value="{{ isset($manualCafc) ? $manualCafc->issued_manually_at->format('Y-m-d\\TH:i:s') : now()->format('Y-m-d\\TH:i') }}">

                <label class="form-label" for="invoice-payment-method">Método de pago</label>
                <select class="form-select" id="invoice-payment-method" name="payment_method_code" data-tom-select data-allow-empty-option="false" data-placeholder="Buscar metodo de pago">
                    <option value="" disabled selected>Seleccionar metodo</option>
                    @foreach ($paymentMethods as $paymentMethod)
                        @php($normalizedPaymentMethod = preg_replace('/[^a-z0-9]+/', '', strtolower(\Illuminate\Support\Str::ascii((string) $paymentMethod->description))))
                        @php($isGiftCardPayment = str_contains($normalizedPaymentMethod, 'gift') || str_contains($normalizedPaymentMethod, 'tarjetaregalo'))
                        <option value="{{ $paymentMethod->classifier_code }}" data-is-gift-card="{{ $isGiftCardPayment ? '1' : '0' }}" @selected((string) $paymentMethod->classifier_code === '1')>
                            {{ $paymentMethod->classifier_code }} - {{ $paymentMethod->description }}
                        </option>
                    @endforeach
                </select>

                <div class="mt-3 d-none" data-invoice-card-field>
                    <label class="form-label" for="invoice-card-number">Número de tarjeta</label>
                    <input class="form-control font-monospace" id="invoice-card-number" name="card_number"
                        type="text" inputmode="numeric" autocomplete="off" maxlength="19"
                        placeholder="1234 5678 9012 3456" data-invoice-card-number>
                    <div class="form-text">Solo se conservarán los primeros y últimos 4 dígitos; los 8 centrales se reemplazarán por ceros.</div>
                </div>

                <label class="form-label mt-3" for="invoice-currency">Moneda</label>
                <select class="form-select" id="invoice-currency" name="currency_code" data-tom-select data-allow-empty-option="false" data-placeholder="Buscar moneda">
                    <option value="" disabled>Seleccionar moneda</option>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->classifier_code }}" @selected((string) $currency->classifier_code === (string) $defaultCurrencyCode)>
                            {{ $currency->classifier_code }} - {{ $currency->description }}
                        </option>
                    @endforeach
                </select>
                <div class="mt-3 d-none" data-invoice-exchange-rate-field>
                    <label class="form-label" for="invoice-exchange-rate">Tipo de cambio</label>
                    <input class="form-control text-end" id="invoice-exchange-rate" name="exchange_rate" type="number" min="0.00001" step="0.00001" value="1.00">
                    <div class="form-hint">Valor de una unidad de la moneda seleccionada en bolivianos</div>
                </div>
                <label class="form-label mt-3" for="invoice-gift-card">Monto Gift Card</label>
                <input class="form-control text-end" id="invoice-gift-card" name="gift_card_amount" type="number" min="0" step="0.01" value="0.00" data-invoice-gift-card disabled>
                <div class="form-hint">Se habilita únicamente para métodos de pago que incluyen Gift Card.</div>

                <div class="invoice-totals">
                    <div>
                        <span>SubTotal</span>
                        <strong data-invoice-subtotal>BO 0.00</strong>
                    </div>
                    <div class="invoice-total-discount">
                        <span>Monto descuento</span>
                        <div class="input-group">
                            <select class="form-select" name="additional_discount_type" data-invoice-total-discount-type><option value="FIXED">Bs</option><option value="PERCENTAGE">%</option></select>
                            <input class="form-control text-end" name="total_discount" type="number" min="0" step="0.5" value="0" data-invoice-total-discount>
                            <input name="additional_discount_percentage" type="hidden" value="" data-invoice-total-discount-percentage>
                        </div>
                    </div>
                    <div>
                        <span>Total</span>
                        <strong data-invoice-total>BO 0.00</strong>
                    </div>
                    <div>
                        <span>Monto total sujeto IVA</span>
                        <strong data-invoice-taxable-total>BO 0.00</strong>
                    </div>
                </div>

                <div class="invoice-actions">
                    <a class="btn btn-outline-secondary" href="{{ isset($manualCafc) ? route('billing.cafc-contingencies.show', $manualCafc->sin_cafc_range_id) : route('billing.invoices.issue.index') }}">
                        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Volver
                    </a>
                    <button class="btn btn-warning" type="reset" data-invoice-clear>
                        <i class="ti ti-eraser me-1" aria-hidden="true"></i>Limpiar
                    </button>
                    <button class="btn btn-success" type="button" data-invoice-submit disabled>
                        <i class="ti ti-send me-1" aria-hidden="true"></i><span data-invoice-submit-label>{{ isset($manualCafc) ? 'Transcribir' : 'Emitir' }}</span>
                    </button>
                </div>
            </aside>
        </div>
    </form>
@endsection
