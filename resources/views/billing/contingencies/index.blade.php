@extends('layouts.admin')

@section('title', 'Contingencias | '.config('app.name'))
@section('page-title', 'Monitor de contingencias')
@section('page-subtitle', 'Estado operativo y regularización de facturas ante el SIAT')

@php
    $communicationAvailable = $latestCommunication?->outcome === \App\Enums\SiatCommunicationOutcome::Available;
    $fiscalLabel = fn ($status) => match ($status) {
        \App\Enums\InvoiceFiscalStatus::NotIssued => 'No emitida',
        \App\Enums\InvoiceFiscalStatus::PendingOnlineSend => 'Pendiente de envío',
        \App\Enums\InvoiceFiscalStatus::Validated => 'Validada',
        \App\Enums\InvoiceFiscalStatus::Observed => 'Observada',
        \App\Enums\InvoiceFiscalStatus::Rejected => 'Rechazada',
        \App\Enums\InvoiceFiscalStatus::UncertainSend => 'Envío incierto',
        \App\Enums\InvoiceFiscalStatus::OfflineIssued => 'Emitida fuera de línea',
        \App\Enums\InvoiceFiscalStatus::PendingPackage => 'Pendiente de paquete',
        \App\Enums\InvoiceFiscalStatus::Packaged => 'Empaquetada',
        \App\Enums\InvoiceFiscalStatus::PackageSent => 'Paquete enviado',
        \App\Enums\InvoiceFiscalStatus::ValidatedAfterContingency => 'Validada después de contingencia',
        \App\Enums\InvoiceFiscalStatus::ManualPendingTranscription => 'Manual pendiente de transcripción',
        \App\Enums\InvoiceFiscalStatus::ManualTranscribed => 'Manual transcrita',
        \App\Enums\InvoiceFiscalStatus::ManualPendingSend => 'Manual pendiente de envío',
        \App\Enums\InvoiceFiscalStatus::ManualValidated => 'Manual validada',
        \App\Enums\InvoiceFiscalStatus::CancellationPending => 'Anulación pendiente',
        \App\Enums\InvoiceFiscalStatus::CancelledInSiat => 'Anulada en SIAT',
        default => $status?->label() ?? '-',
    };
    $commercialLabel = fn ($status) => match ($status) {
        \App\Enums\InvoiceCommercialStatus::Draft => 'Borrador',
        \App\Enums\InvoiceCommercialStatus::Confirmed => 'Confirmada',
        \App\Enums\InvoiceCommercialStatus::Paid => 'Pagada',
        \App\Enums\InvoiceCommercialStatus::Cancelled => 'Anulada',
        default => '-',
    };
    $fiscalTone = fn ($status) => match ($status) {
        \App\Enums\InvoiceFiscalStatus::Validated,
        \App\Enums\InvoiceFiscalStatus::ValidatedAfterContingency,
        \App\Enums\InvoiceFiscalStatus::ManualValidated => 'success',
        \App\Enums\InvoiceFiscalStatus::Rejected,
        \App\Enums\InvoiceFiscalStatus::CancelledInSiat => 'danger',
        \App\Enums\InvoiceFiscalStatus::Observed,
        \App\Enums\InvoiceFiscalStatus::UncertainSend => 'warning',
        default => 'primary',
    };
    $packageTone = fn ($status) => match ($status) {
        \App\Enums\InvoicePackageStatus::Validated => 'success',
        \App\Enums\InvoicePackageStatus::Observed => 'warning',
        \App\Enums\InvoicePackageStatus::Rejected,
        \App\Enums\InvoicePackageStatus::Failed => 'danger',
        default => 'primary',
    };
    $eventCanBeConfigured = $openEvent
        && in_array($openEvent->event_status, [
            \App\Enums\SignificantEventStatus::Open,
            \App\Enums\SignificantEventStatus::RecoveryDetected,
            \App\Enums\SignificantEventStatus::PendingRegistration,
            \App\Enums\SignificantEventStatus::Failed,
        ], true)
        && ! $openEvent->transaccion
        && ! $openEvent->manual_review_required
        && $openEvent->registration_claim === null;
@endphp

@section('content')
    <section class="card contingency-filter-card mb-3" aria-labelledby="contingency-filters-heading">
        <div class="card-header">
            <div>
                <h2 class="card-title" id="contingency-filters-heading">Contexto de supervisión</h2>
                <div class="text-secondary small">Los indicadores y resultados pertenecen al contexto seleccionado.</div>
            </div>
            <div class="card-actions"><a class="btn btn-outline-secondary btn-sm" href="{{ route('billing.contingencies.index') }}"><i class="ti ti-refresh me-1" aria-hidden="true"></i>Actualizar</a></div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('billing.contingencies.index') }}" class="row g-2 align-items-end" autocomplete="off">
                <div class="col-12 col-md-4 col-xl-3">
                    <label class="form-label" for="company_id">Empresa</label>
                    @if ($companies->count() > 1)
                        <select class="form-select" id="company_id" name="company_id" required>
                            @foreach ($companies as $option)<option value="{{ $option->id }}" @selected($company->id === $option->id)>{{ $option->name }} · NIT {{ $option->tax_id }}</option>@endforeach
                        </select>
                    @else
                        <input class="form-control" value="{{ $company->name }} · NIT {{ $company->tax_id }}" readonly aria-label="Empresa seleccionada">
                        <input type="hidden" name="company_id" value="{{ $company->id }}">
                    @endif
                </div>
                <div class="col-6 col-md-4 col-xl-3">
                    <label class="form-label" for="branch_id">Sucursal</label>
                    <select class="form-select" id="branch_id" name="branch_id">
                        @foreach ($branches as $option)<option value="{{ $option->id }}" @selected($branch?->id === $option->id)>{{ $option->display_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-4 col-xl-3">
                    <label class="form-label" for="point_of_sale_id">Punto de venta</label>
                    <select class="form-select" id="point_of_sale_id" name="point_of_sale_id">
                        @foreach ($points as $option)<option value="{{ $option->id }}" @selected($point?->id === $option->id)>{{ $option->display_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-12 col-xl-3 d-grid"><button class="btn btn-primary" type="submit"><i class="ti ti-adjustments-horizontal me-1" aria-hidden="true"></i>Aplicar contexto</button></div>

                <div class="col-12">
                    <details class="contingency-advanced-filters" @if(collect($filters)->except(['company_id','branch_id','point_of_sale_id'])->filter(fn($value) => filled($value))->isNotEmpty()) open @endif>
                        <summary>Filtros de facturas e historial</summary>
                        <div class="row g-2 pt-3">
                            <div class="col-6 col-lg-3"><label class="form-label" for="status">Estado fiscal</label><select class="form-select form-select-sm" id="status" name="status"><option value="">Todos</option>@foreach($fiscalStatuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $fiscalLabel($status) }}</option>@endforeach</select></div>
                            <div class="col-6 col-lg-3"><label class="form-label" for="modality">Modalidad</label><select class="form-select form-select-sm" id="modality" name="modality"><option value="">Todas</option>@foreach($emissionModes as $mode)<option value="{{ $mode->value }}" @selected(($filters['modality'] ?? null) === $mode->value)>{{ str_replace('_', ' ', $mode->value) }}</option>@endforeach</select></div>
                            <div class="col-6 col-lg-3"><label class="form-label" for="date_from">Desde</label><input class="form-control form-control-sm" id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></div>
                            <div class="col-6 col-lg-3"><label class="form-label" for="date_to">Hasta</label><input class="form-control form-control-sm" id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></div>
                            <div class="col-6 col-lg-2"><label class="form-label" for="event_id">Evento</label><input class="form-control form-control-sm" id="event_id" name="event_id" type="number" min="1" value="{{ $filters['event_id'] ?? '' }}"></div>
                            <div class="col-6 col-lg-2"><label class="form-label" for="number">Número</label><input class="form-control form-control-sm" id="number" name="number" type="number" min="1" value="{{ $filters['number'] ?? '' }}"></div>
                            <div class="col-12 col-lg-3"><label class="form-label" for="cuf">CUF</label><input class="form-control form-control-sm" id="cuf" name="cuf" value="{{ $filters['cuf'] ?? '' }}" maxlength="256"></div>
                            <div class="col-12 col-lg-3"><label class="form-label" for="client">Cliente o documento</label><input class="form-control form-control-sm" id="client" name="client" value="{{ $filters['client'] ?? '' }}" maxlength="120"></div>
                            <div class="col-12 col-lg-2 d-flex gap-2 align-items-end"><button class="btn btn-outline-primary btn-sm flex-fill" type="submit">Filtrar</button><a class="btn btn-outline-secondary btn-sm" href="{{ route('billing.contingencies.index', ['company_id' => $company->id, 'branch_id' => $branch?->id, 'point_of_sale_id' => $point?->id]) }}" aria-label="Limpiar filtros"><i class="ti ti-x" aria-hidden="true"></i></a></div>
                        </div>
                    </details>
                </div>
            </form>
        </div>
    </section>

    <section class="contingency-regularization-line mb-3" aria-label="Línea de regularización SIAT">
        <article class="contingency-line-node {{ $communicationAvailable ? 'is-ok' : 'is-alert' }}">
            <span class="contingency-line-kicker">Conexión SIAT</span>
            <strong>{{ $latestCommunication ? ($communicationAvailable ? 'Disponible' : 'No disponible') : 'Sin consulta' }}</strong>
            <small>{{ $latestCommunication?->checked_at?->format('d/m/Y H:i:s') ?? 'Aún no verificada' }}</small>
        </article>
        <article class="contingency-line-node {{ $activeCuis ? 'is-ok' : 'is-muted' }}">
            <span class="contingency-line-kicker">CUIS activo</span>
            <strong class="font-monospace">{{ $activeCuis?->cuis_code ? '••••'.substr($activeCuis->cuis_code, -6) : 'No disponible' }}</strong>
            <small>{{ $branch?->display_name ?? 'Sin sucursal' }}</small>
        </article>
        <article class="contingency-line-node {{ $currentCufd?->expires_at?->isFuture() ? 'is-ok' : 'is-alert' }}">
            <span class="contingency-line-kicker">CUFD actual</span>
            <strong class="font-monospace">{{ $currentCufd?->cufd_code ? '••••'.substr($currentCufd->cufd_code, -8) : 'No disponible' }}</strong>
            <small>{{ $currentCufd?->expires_at ? 'Vence '.$currentCufd->expires_at->format('d/m/Y H:i') : 'Sin vencimiento registrado' }}</small>
        </article>
        <article class="contingency-line-node {{ $openEvent ? 'is-warning' : 'is-ok' }}">
            <span class="contingency-line-kicker">Evento abierto</span>
            <strong>{{ $openEvent ? '#'.$openEvent->id.' · '.$openEvent->event_status->label() : 'Sin evento abierto' }}</strong>
            <small>{{ $openEvent?->started_at?->format('d/m/Y H:i:s') ?? 'Operación normal' }}</small>
        </article>
        <article class="contingency-line-node {{ $openEvent?->expires_at?->isPast() ? 'is-alert' : 'is-muted' }}">
            <span class="contingency-line-kicker">Regularización</span>
            <strong data-contingency-duration data-start="{{ $openEvent?->started_at ? \App\Services\Siat\SiatDateTime::localIso($openEvent->started_at) : null }}">{{ $openEvent ? $openEvent->started_at->diffForHumans(now(), true) : '—' }}</strong>
            <small>{{ $openEvent?->expires_at ? 'Plazo '.$openEvent->expires_at->format('d/m/Y H:i') : 'Sin plazo registrado' }}</small>
        </article>
    </section>

    <div class="d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center mb-3">
        <div class="text-secondary small">PV seleccionado: <strong class="text-body">{{ $point?->display_name ?? 'Sin punto de venta activo' }}</strong></div>
        <div class="d-flex flex-wrap gap-2">
            @can('contingencies.communication.check')
                <form method="POST" action="{{ route('billing.contingencies.communication.check') }}">@csrf<input type="hidden" name="company_id" value="{{ $company->id }}"><input type="hidden" name="point_of_sale_id" value="{{ $point?->id }}"><button class="btn btn-primary btn-sm" type="submit" @disabled(! $apiToken || ! $point)><i class="ti ti-plug-connected me-1" aria-hidden="true"></i>Consultar comunicación</button></form>
            @endcan
            @if($openEvent)
                @can('contingencies.events.view')<a class="btn btn-outline-secondary btn-sm" href="{{ route('billing.contingencies.events.show', $openEvent) }}" data-modal-url="{{ route('billing.contingencies.events.show', $openEvent) }}" data-modal-title="Evento significativo #{{ $openEvent->id }}" data-modal-size="xl"><i class="ti ti-eye me-1" aria-hidden="true"></i>Ver evento</a>@endcan
                @can('contingencies.events.retry')
                    @if($eventCanBeConfigured)
                        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#register-significant-event-modal" @disabled($significantEventTypes->isEmpty())>
                            <i class="ti ti-file-upload me-1" aria-hidden="true"></i>Registrar evento significativo
                        </button>
                    @elseif(in_array($openEvent->event_status, [\App\Enums\SignificantEventStatus::RecoveryDetected, \App\Enums\SignificantEventStatus::PendingRegistration, \App\Enums\SignificantEventStatus::Failed], true) && ! $openEvent->transaccion && ! $openEvent->manual_review_required)
                        <form method="POST" action="{{ route('billing.contingencies.events.retry', $openEvent) }}">
                            @csrf
                            <button class="btn btn-primary btn-sm" type="submit">
                                <i class="ti ti-reload me-1" aria-hidden="true"></i>Reintentar registro
                            </button>
                        </form>
                    @endif
                @endcan
                @can('contingencies.packages.build')@if(in_array($openEvent->event_status, [\App\Enums\SignificantEventStatus::Registered, \App\Enums\SignificantEventStatus::Packaging], true) && $openEvent->transaccion && filled($openEvent->reception_code) && $openEvent->has_eligible_offline_invoices)<form method="POST" action="{{ route('billing.contingencies.packages.build', $openEvent) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit"><i class="ti ti-package-export me-1" aria-hidden="true"></i>Generar paquetes</button></form>@endif @endcan
            @endif
        </div>
    </div>

    @if($openEvent?->message)
        <div class="alert {{ $openEvent->transaccion && $openEvent->reception_code ? 'alert-success' : 'alert-warning' }} mb-3" role="status">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                <div>
                    <strong>{{ $openEvent->transaccion && $openEvent->reception_code ? 'Evento confirmado por SIAT' : 'Último resultado del registro' }}</strong>
                    <div>{{ $openEvent->message }}</div>
                </div>
                <div class="text-md-end">
                    <div class="small text-secondary">Código de recepción</div>
                    <div class="font-monospace">{{ $openEvent->reception_code ?: 'No recibido' }}</div>
                </div>
            </div>
        </div>
    @endif

    <section class="contingency-metrics mb-3" aria-label="Indicadores de contingencia">
        @foreach ([
            ['Alertas activas', $metrics['active_alerts'], 'ti-bell-ringing', 'red'],
            ['Facturas fuera de línea', $metrics['offline_invoices'], 'ti-wifi-off', 'blue'],
            ['Pendientes de envío', $metrics['pending_invoices'], 'ti-clock-upload', 'azure'],
            ['Paquetes pendientes', $metrics['pending_packages'], 'ti-package', 'indigo'],
            ['Paquetes observados', $metrics['observed_packages'], 'ti-eye-exclamation', 'yellow'],
            ['Facturas rechazadas', $metrics['rejected_invoices'], 'ti-file-x', 'red'],
            ['Manuales por transcribir', $metrics['manual_pending'], 'ti-file-pencil', 'orange'],
            ['Rangos CAFC disponibles', $metrics['available_cafc'], 'ti-number', 'green'],
        ] as [$label, $value, $icon, $tone])
            <article class="card contingency-metric-card">
                <div class="card-body"><span class="avatar avatar-sm bg-{{ $tone }}-lt"><i class="ti {{ $icon }}" aria-hidden="true"></i></span><strong>{{ number_format($value) }}</strong><span>{{ $label }}</span></div>
            </article>
        @endforeach
    </section>

    @if ($activeAlerts->isNotEmpty())
        <section class="card contingency-alert-panel mb-3" aria-labelledby="active-alerts-heading">
            <div class="card-header">
                <div>
                    <h2 class="card-title" id="active-alerts-heading">Alertas operativas activas</h2>
                    <div class="text-secondary small">Una condición persistente se muestra una sola vez y se actualiza hasta resolverse.</div>
                </div>
                <span class="badge bg-danger-lt">{{ $activeAlerts->count() }} visibles</span>
            </div>
            <div class="list-group list-group-flush">
                @foreach ($activeAlerts as $alert)
                    @php
                        $alertTone = match ($alert->severity) {
                            \App\Enums\SiatAlertSeverity::Critical => 'danger',
                            \App\Enums\SiatAlertSeverity::Warning => 'warning',
                            default => 'primary',
                        };
                    @endphp
                    <article class="list-group-item contingency-alert-row">
                        <span class="contingency-alert-rail bg-{{ $alertTone }}" aria-hidden="true"></span>
                        <div class="flex-fill min-w-0">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <strong>{{ $alert->title }}</strong>
                                <span class="badge bg-{{ $alertTone }}-lt">{{ $alert->severity->label() }}</span>
                                @if ($alert->condition_count > 1)<span class="badge bg-secondary-lt">{{ $alert->condition_count }} elementos</span>@endif
                            </div>
                            <div class="text-secondary small mt-1">{{ $alert->message }}</div>
                            <div class="text-secondary mt-1" style="font-size: .72rem">
                                {{ $alert->branch?->display_name ?? 'Toda la empresa' }}
                                @if($alert->pointOfSale) · {{ $alert->pointOfSale->display_name }} @endif
                                · detectada {{ $alert->last_detected_at->diffForHumans() }}
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
            <x-ui.table-card title="Paquetes recientes">
                <table class="table table-vcenter">
                    <thead><tr><th>Paquete</th><th>Evento</th><th>Facturas</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                    <tbody>
                    @forelse($packages as $package)
                        <tr>
                            <td><div class="fw-semibold">#{{ $package->package_number }}</div><div class="text-secondary small">{{ $package->generated_at?->format('d/m/Y H:i') }}</div></td>
                            <td>#{{ $package->sin_significant_event_id }}</td><td>{{ $package->invoice_count }}</td>
                            <td><span class="badge bg-{{ $packageTone($package->package_status) }}-lt">{{ $package->package_status->label() }}</span></td>
                            <td class="text-end"><div class="btn-list justify-content-end">
                                @can('contingencies.packages.send')@if(in_array($package->package_status, [\App\Enums\InvoicePackageStatus::Created, \App\Enums\InvoicePackageStatus::PendingSend, \App\Enums\InvoicePackageStatus::Failed], true))<form method="POST" action="{{ route('billing.contingencies.packages.send', $package) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit">{{ $package->package_status === \App\Enums\InvoicePackageStatus::Failed ? 'Reintentar envío' : 'Enviar al SIN' }}</button></form>@endif @endcan
                                @can('contingencies.packages.validate')@if(in_array($package->package_status, [\App\Enums\InvoicePackageStatus::Sent, \App\Enums\InvoicePackageStatus::PendingValidation, \App\Enums\InvoicePackageStatus::Observed], true))<form method="POST" action="{{ route('billing.contingencies.packages.validate', $package) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit">Consultar</button></form>@endif @endcan
                                @can('contingencies.technical.view')<a class="btn btn-icon btn-ghost-secondary" href="{{ route('billing.contingencies.technical.show', ['package', $package->id]) }}" data-modal-url="{{ route('billing.contingencies.technical.show', ['package', $package->id]) }}" data-modal-title="Respuesta técnica del paquete" aria-label="Ver respuesta técnica"><i class="ti ti-code" aria-hidden="true"></i></a>@endcan
                                @can('contingencies.audit.view')@can('audits.view')<a class="btn btn-icon btn-ghost-secondary" href="{{ route('audits.index', ['auditable_type' => \App\Models\SinInvoicePackage::class]) }}" aria-label="Ver auditoría de paquetes"><i class="ti ti-history" aria-hidden="true"></i></a>@endcan @endcan
                            </div></td>
                        </tr>
                    @empty <x-ui.empty-row colspan="5" message="No hay paquetes para el contexto seleccionado." /> @endforelse
                    </tbody>
                </table>
            </x-ui.table-card>
        </div>
        <div class="col-12 col-xl-5">
            <x-ui.table-card title="Pendientes manuales y CAFC">
                <table class="table table-vcenter">
                    <thead><tr><th>Documento</th><th>Fecha original</th><th class="text-end">Acción</th></tr></thead>
                    <tbody>
                    @forelse($manualInvoices as $manual)
                        <tr><td><div class="fw-semibold">Manual N.º {{ $manual->manual_invoice_number }}</div><div class="text-secondary small">CAFC {{ $manual->cafcRange->cafc_code }}</div></td><td class="text-nowrap">{{ $manual->issued_manually_at->format('d/m/Y H:i') }}</td><td class="text-end">@if(auth()->user()->company_id) @can('manual-cafc.transcribe')<a class="btn btn-primary btn-sm" href="{{ route('billing.manual-cafc.transcribe.edit', $manual) }}">Transcribir</a>@endcan @else <span class="text-secondary small">Requiere usuario de empresa</span> @endif</td></tr>
                    @empty <x-ui.empty-row colspan="3" message="No hay facturas manuales pendientes." /> @endforelse
                    </tbody>
                </table>
                <x-slot:footer><div class="d-flex flex-wrap gap-2 justify-content-between align-items-center"><span class="text-secondary small">{{ $cafcRanges->count() }} rangos vigentes en este contexto.</span>@if(auth()->user()->company_id) @can('manual-cafc.use')<a class="btn btn-outline-danger btn-sm" href="{{ route('billing.manual-cafc.index') }}"><i class="ti ti-file-off me-1" aria-hidden="true"></i>Registrar factura física anulada</a>@endcan @endif</div></x-slot:footer>
            </x-ui.table-card>
        </div>
    </div>

    <div class="mb-3">
        <x-ui.table-card title="Facturas monitoreadas">
            <table class="table table-hover table-vcenter">
                <thead><tr><th>Factura / CUF</th><th>Cliente</th><th>Modalidad</th><th>Estado comercial</th><th>Estado fiscal</th><th>Evento</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                @forelse($invoices as $invoice)
                    <tr>
                        <td><div class="fw-semibold">N.º {{ $invoice->invoice_number ?? $invoice->attempted_invoice_number }}</div><div class="font-monospace text-secondary small text-break" title="{{ $invoice->cuf }}">{{ \Illuminate\Support\Str::limit($invoice->cuf, 24) }}</div><div class="text-secondary small">{{ $invoice->issued_at->format('d/m/Y H:i:s') }}</div></td>
                        <td><div>{{ $invoice->customer?->name ?? 'Sin cliente' }}</div><div class="text-secondary small">{{ $invoice->customer?->document_number }}</div></td>
                        <td><span class="badge bg-secondary-lt">{{ str_replace('_', ' ', $invoice->emission_mode->value) }}</span></td>
                        <td><span class="badge bg-secondary-lt"><i class="ti ti-shopping-cart-check me-1" aria-hidden="true"></i>{{ $commercialLabel($invoice->commercial_status) }}</span></td>
                        <td><span class="badge bg-{{ $fiscalTone($invoice->fiscal_status) }}-lt"><i class="ti ti-building-bank me-1" aria-hidden="true"></i>{{ $fiscalLabel($invoice->fiscal_status) }}</span></td>
                        <td>{{ $invoice->sin_significant_event_id ? '#'.$invoice->sin_significant_event_id : '—' }}</td>
                        <td class="text-end"><div class="dropdown"><button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Acciones</button><div class="dropdown-menu dropdown-menu-end">
                            @can('contingencies.artifacts.download')@if($invoice->xml_path)<a class="dropdown-item" href="{{ route('billing.contingencies.invoices.xml', $invoice) }}"><i class="ti ti-file-code me-2" aria-hidden="true"></i>Descargar XML</a>@endif @if($invoice->payload)<a class="dropdown-item" href="{{ route('billing.contingencies.invoices.pdf', $invoice) }}"><i class="ti ti-file-type-pdf me-2" aria-hidden="true"></i>Descargar representación</a>@endif @endcan
                            @can('contingencies.technical.view')<a class="dropdown-item" href="{{ route('billing.contingencies.technical.show', ['invoice', $invoice->id]) }}" data-modal-url="{{ route('billing.contingencies.technical.show', ['invoice', $invoice->id]) }}" data-modal-title="Respuesta técnica de factura" data-modal-size="xl"><i class="ti ti-code me-2" aria-hidden="true"></i>Ver respuesta técnica</a>@endcan
                            @if($invoice->sin_significant_event_id)@can('contingencies.events.view')<a class="dropdown-item" href="{{ route('billing.contingencies.events.show', $invoice->sin_significant_event_id) }}" data-modal-url="{{ route('billing.contingencies.events.show', $invoice->sin_significant_event_id) }}" data-modal-title="Evento significativo"><i class="ti ti-alert-triangle me-2" aria-hidden="true"></i>Ver evento</a>@endcan @endif
                            @can('contingencies.audit.view')@can('audits.view')<a class="dropdown-item" href="{{ route('audits.index', ['auditable_type' => \App\Models\SinInvoiceIssue::class]) }}"><i class="ti ti-history me-2" aria-hidden="true"></i>Ver auditoría</a>@endcan @endcan
                        </div></div></td>
                    </tr>
                @empty <x-ui.empty-row colspan="7" message="No hay facturas que coincidan con los filtros." /> @endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $invoices->links() }}</x-slot:footer>
        </x-ui.table-card>
    </div>

    <x-ui.table-card title="Historial de eventos significativos">
        <table class="table table-vcenter">
            <thead><tr><th>Evento</th><th>Sucursal / PV</th><th>Inicio</th><th>Fin real</th><th>Duración</th><th>Plazo</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            @forelse($events as $event)
                <tr>
                    <td><div class="fw-semibold">#{{ $event->id }} · Código {{ $event->event_code }}</div><div class="text-secondary small">{{ $event->event_description }}</div></td>
                    <td>{{ $event->branch?->display_name }}<br><span class="text-secondary small">{{ $event->pointOfSale?->display_name }}</span></td>
                    <td class="text-nowrap">{{ $event->started_at->format('d/m/Y H:i:s') }}</td><td class="text-nowrap">{{ $event->recovery_detected_at?->format('d/m/Y H:i:s') ?? '—' }}</td>
                    <td>{{ $event->started_at->diffForHumans($event->recovery_detected_at ?? now(), true) }}</td><td>{{ $event->expires_at?->format('d/m/Y H:i') ?? 'Sin plazo registrado' }}</td>
                    <td><span class="badge bg-{{ $event->event_status === \App\Enums\SignificantEventStatus::Completed ? 'success' : ($event->event_status === \App\Enums\SignificantEventStatus::Failed ? 'danger' : 'primary') }}-lt">{{ $event->event_status->label() }}</span></td>
                    <td class="text-end"><div class="btn-list justify-content-end">@can('contingencies.events.view')<a class="btn btn-outline-primary btn-sm" href="{{ route('billing.contingencies.events.show', $event) }}" data-modal-url="{{ route('billing.contingencies.events.show', $event) }}" data-modal-title="Evento significativo #{{ $event->id }}" data-modal-size="xl">Ver</a>@endcan @can('contingencies.technical.view')<a class="btn btn-icon btn-ghost-secondary" href="{{ route('billing.contingencies.technical.show', ['event', $event->id]) }}" data-modal-url="{{ route('billing.contingencies.technical.show', ['event', $event->id]) }}" data-modal-title="Respuesta técnica del evento" aria-label="Ver respuesta técnica"><i class="ti ti-code" aria-hidden="true"></i></a>@endcan @can('contingencies.audit.view')@can('audits.view')<a class="btn btn-icon btn-ghost-secondary" href="{{ route('audits.index', ['auditable_type' => \App\Models\SinSignificantEvent::class]) }}" aria-label="Ver auditoría de eventos"><i class="ti ti-history" aria-hidden="true"></i></a>@endcan @endcan</div></td>
                </tr>
            @empty <x-ui.empty-row colspan="8" message="No existen eventos para el contexto y fechas seleccionados." /> @endforelse
            </tbody>
        </table>
        <x-slot:footer>{{ $events->links() }}</x-slot:footer>
    </x-ui.table-card>

    @if($eventCanBeConfigured)
        <div class="modal fade" id="register-significant-event-modal" tabindex="-1" aria-labelledby="register-significant-event-title" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('billing.contingencies.events.register', $openEvent) }}">
                    @csrf
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title h3" id="register-significant-event-title">Registrar evento significativo</h2>
                            <div class="text-secondary small">Selecciona el motivo oficial informado por Impuestos para el evento #{{ $openEvent->id }}.</div>
                        </div>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        @if($significantEventTypes->isEmpty())
                            <div class="alert alert-danger mb-0">No existen eventos significativos vigentes. Sincroniza el catálogo de Impuestos antes de continuar.</div>
                        @else
                            <div class="mb-3">
                                <label class="form-label required" for="register-event-code">Evento significativo de Impuestos</label>
                                <select class="form-select @error('event_code') is-invalid @enderror" id="register-event-code" name="event_code" required>
                                    <option value="">Seleccionar evento</option>
                                    @foreach($significantEventTypes as $eventType)
                                        <option
                                            value="{{ $eventType->classifier_code }}"
                                            data-description="{{ $eventType->description }}"
                                            @selected((string) old('event_code', $openEvent->event_code) === (string) $eventType->classifier_code)
                                        >{{ $eventType->classifier_code }} - {{ $eventType->description }}</option>
                                    @endforeach
                                </select>
                                @error('event_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="form-label required" for="register-event-description">Descripción de la contingencia</label>
                                <textarea class="form-control @error('description') is-invalid @enderror" id="register-event-description" name="description" rows="3" maxlength="500" required>{{ old('description', $openEvent->event_description) }}</textarea>
                                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="alert alert-info mt-3 mb-0">Al continuar se verificará la conexión, se obtendrá un CUFD nuevo y se registrará el evento ante SIAT. Los paquetes solo se generarán si SIAT devuelve un código de recepción.</div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-primary" type="submit" @disabled($significantEventTypes->isEmpty())>Comunicar y registrar en SIAT</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    @if($errors->has('event_code') || $errors->has('description'))
        const registrationModal = document.getElementById('register-significant-event-modal');
        if (registrationModal) bootstrap.Modal.getOrCreateInstance(registrationModal).show();
    @endif
    const registrationEventCode = document.getElementById('register-event-code');
    const registrationDescription = document.getElementById('register-event-description');
    registrationEventCode?.addEventListener('change', () => {
        const selected = registrationEventCode.options[registrationEventCode.selectedIndex];
        if (registrationDescription && selected?.dataset.description) {
            registrationDescription.value = selected.dataset.description;
        }
    });
    const timer = document.querySelector('[data-contingency-duration]');
    if (!timer?.dataset.start) return;
    const start = new Date(timer.dataset.start).getTime();
    const update = () => {
        const seconds = Math.max(0, Math.floor((Date.now() - start) / 1000));
        const days = Math.floor(seconds / 86400); const hours = Math.floor((seconds % 86400) / 3600); const minutes = Math.floor((seconds % 3600) / 60);
        timer.textContent = `${days ? `${days}d ` : ''}${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    };
    update(); window.setInterval(update, 60000);
});
</script>
@endpush
