@extends('layouts.admin')

@section('title', 'CAFC '.$range->cafc_code.' | '.config('app.name'))
@section('page-title', 'CAFC '.$range->cafc_code)
@section('page-subtitle', $sectorTitle.' · '.$range->branch->display_name)

@section('content')
@can('cafc-ranges.manage')
    @if($canRestartTests)
        <form method="POST" action="{{ route('billing.cafc-ranges.destroy', $range) }}" class="mb-3 text-end" data-confirm-action data-confirm-title="¿Eliminar este CAFC de pruebas?" data-confirm-text="Podrá registrar nuevamente el mismo código y numeración. El historial anterior se conserva y esta acción no elimina registros en el SIN." data-confirm-button="Sí, eliminar de pruebas">
            @csrf @method('DELETE')
            <input type="hidden" name="restart_tests" value="1">
            <button class="btn btn-outline-danger" type="submit"><i class="ti ti-trash me-1"></i>Eliminar de pruebas</button>
        </form>
    @endif
@endcan
<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="card c2-identity mb-3"><div class="card-body"><span class="text-uppercase small fw-bold text-primary">Autorización activa</span><div class="display-6 fw-bold my-2">{{ $range->remaining_count }}</div><div class="text-secondary">números disponibles de {{ $range->range_start }} a {{ $range->range_end }}</div><hr><dl class="row mb-0"><dt class="col-5">Sector</dt><dd class="col-7">{{ $sectorTitle }}</dd><dt class="col-5">Evento SIN</dt><dd class="col-7">@if($range->significantEvent)<strong>{{ $range->significantEvent->event_code }}</strong> · {{ $range->significantEvent->event_description }}<div class="small text-success">Recepción: {{ $range->significantEvent->reception_code ?? 'registrada' }}</div>@else<span class="badge bg-warning-lt">Pendiente de registro</span>@endif</dd>@if($range->significantEvent)<dt class="col-5">Periodo evento</dt><dd class="col-7">{{ $range->significantEvent->started_at?->format('d/m/Y H:i:s') }}<br>{{ $range->significantEvent->ended_at?->format('d/m/Y H:i:s') }}</dd>@endif<dt class="col-5">Siguiente</dt><dd class="col-7 fw-bold">{{ $range->next_number }}</dd><dt class="col-5">Vigencia</dt><dd class="col-7">{{ $range->authorized_until->format('d/m/Y') }}</dd></dl></div></div>
        @can('cafc-ranges.manage')
            @if(!$range->significantEvent && $range->manualInvoices->isEmpty())
                <div class="card mb-3"><div class="card-header"><div><h3 class="card-title">Código CAFC</h3><div class="small text-secondary">Editable únicamente antes de utilizar la numeración.</div></div></div><form method="POST" action="{{ route('billing.cafc-contingencies.code.update', $range) }}">@csrf @method('PATCH')<div class="card-body"><label class="form-label required" for="cafc-code-edit">Código autorizado</label><input class="form-control @error('cafc_code') is-invalid @enderror" id="cafc-code-edit" name="cafc_code" value="{{ old('cafc_code', $range->cafc_code) }}" maxlength="128" required>@error('cafc_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div><div class="card-footer d-grid"><button class="btn btn-outline-primary"><i class="ti ti-edit me-1"></i>Actualizar código CAFC</button></div></form></div>
            @endif
        @endcan
        @can('manual-cafc.use') @if($range->significantEvent && $canConsume)
        <div class="card"><div class="card-header"><h3 class="card-title">Nueva factura de contingencia</h3></div><form method="POST" action="{{ route('billing.cafc-contingencies.invoices.store', $range) }}">@csrf<div class="card-body"><div class="row g-3">
            <div class="col-12"><label class="form-label">Punto de venta</label><select class="form-select" name="sin_point_of_sale_id" required>@foreach($points as $point)<option value="{{ $point->id }}">{{ $point->display_name }}</option>@endforeach</select></div>
            <div class="col-6"><label class="form-label">Número físico</label><input class="form-control" type="number" name="manual_invoice_number" min="{{ $range->range_start }}" max="{{ $range->range_end }}" value="{{ old('manual_invoice_number', $range->next_number) }}" required></div>
            <div class="col-12"><label class="form-label" for="issued_manually_at">Fecha y hora de la factura</label><input id="issued_manually_at" class="form-control form-control-lg" type="datetime-local" step="1" name="issued_manually_at" min="{{ $range->significantEvent->started_at->format('Y-m-d\TH:i:s') }}" max="{{ $range->significantEvent->ended_at->format('Y-m-d\TH:i:s') }}" value="{{ old('issued_manually_at') }}" required>@error('issued_manually_at')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror</div>
        </div></div><div class="card-footer d-grid"><button class="btn btn-primary" @disabled(!$canConsume)><i class="ti ti-file-pencil me-1"></i>Continuar a transcripción</button></div></form></div>
        @endif @endcan
    </div>
    <div class="col-12 col-xl-8">
        @include('billing.cafc-contingencies.timing')
        @can('manual-cafc.use')
            @if(!$range->significantEvent)
                <div class="card border-warning mb-3">
                    <div class="card-header"><div><h3 class="card-title">1. Registrar evento ante el SIN</h3><div class="small text-secondary">Registre el período real de la contingencia antes de transcribir las facturas.</div></div></div>
                    <form method="POST" action="{{ route('billing.cafc-contingencies.events.store', $range) }}">
                        @csrf
                        <div class="card-body"><div class="row g-3">
                            <div class="col-12"><label class="form-label" for="cafc-event-code">Caso de contingencia</label><select id="cafc-event-code" class="form-select" name="event_code" required data-c2-event><option value="">Seleccionar caso 5, 6 o 7</option>@foreach($events as $event)<option value="{{ $event->classifier_code }}" data-description="{{ $event->description }}" @selected(old('event_code') == $event->classifier_code)>{{ $event->classifier_code }} · {{ $event->description }}</option>@endforeach</select><input type="hidden" name="event_description" data-c2-event-description>@error('event_code')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror</div>
                            <div class="col-md-6"><label class="form-label" for="cafc-event-start">Inicio del evento</label><input id="cafc-event-start" class="form-control form-control-lg" type="datetime-local" step="1" name="event_started_at" value="{{ old('event_started_at') }}" max="{{ $eventClock->format('Y-m-d\TH:i:s') }}" required>@error('event_started_at')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
                            @error('started_at')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror</div>
                            <div class="col-md-6"><label class="form-label" for="cafc-event-end">Fin del evento</label><input id="cafc-event-end" class="form-control form-control-lg" type="datetime-local" step="1" name="event_ended_at" aria-describedby="c2-time-feedback" value="{{ old('event_ended_at') }}" min="{{ $eventClock->copy()->subHours(48)->format('Y-m-d\TH:i:s') }}" max="{{ $eventClock->format('Y-m-d\TH:i:s') }}" required>@error('event_ended_at')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
                            @error('ended_at')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror</div>
                            <div class="col-12"><div id="c2-time-feedback" aria-live="polite" class="mb-2"></div><div class="form-hint">El evento debe registrarse hasta 48 horas después de finalizar la contingencia. Se comprobará el CUFD vigente al inicio del evento. Las facturas manuales deben regularizarse dentro de 72 horas del restablecimiento.</div></div>
                        </div></div>
                        <div class="card-footer text-end"><button class="btn btn-warning"><i class="ti ti-cloud-upload me-1"></i>Registrar evento y habilitar transcripción</button></div>
                    </form>
                </div>
            @elseif($range->significantEvent->event_status === \App\Enums\SignificantEventStatus::Registered && !in_array($range->range_status, [\App\Enums\CafcRangeStatus::Blocked, \App\Enums\CafcRangeStatus::Sent], true))
                <div class="card mb-3"><div class="card-body"><h3 class="card-title">2. Transcribir todas las facturas</h3><p>Ingrese las facturas físicas con su número, fecha y hora originales, dentro del período registrado.</p><form method="POST" action="{{ route('billing.cafc-contingencies.send', $range) }}" data-confirm-action data-confirm-title="¿Finalizar y enviar todas las facturas?" data-confirm-text="Verifique que terminó de transcribir todas las facturas. Al finalizar el CAFC se bloqueará el ingreso de nuevas facturas y se enviarán los paquetes al SIN." data-confirm-button="Sí, finalizar y enviar">@csrf<button class="btn btn-primary"><i class="ti ti-send me-1"></i>3. Finalizar y enviar facturas</button></form></div></div>
            @else
                <div class="alert alert-info">Este CAFC no admite nuevas transcripciones. Consulte el evento y el resultado de los paquetes en Contingencias.</div>
            @endif
        @endcan
        <div class="card"><div class="card-header"><div><h3 class="card-title">Facturas del CAFC</h3><div class="small text-secondary">Historial de números utilizados y su regularización</div></div></div><div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th>Número</th><th>Fecha original</th><th>Evento</th><th>Cliente</th><th>Estado</th><th></th></tr></thead><tbody>
        @forelse($range->manualInvoices->sortByDesc('issued_manually_at') as $manual)<tr><td class="fw-bold">{{ $manual->manual_invoice_number }}</td><td>{{ $manual->issued_manually_at->format('d/m/Y H:i:s') }}</td><td>{{ $manual->significantEvent?->event_code }} · {{ $manual->significantEvent?->event_description }}</td><td>{{ $manual->customer?->name ?? 'Pendiente' }}</td><td><span class="badge bg-blue-lt">{{ $manual->manual_status->label() }}</span></td><td class="text-end">@if($manual->manual_status === \App\Enums\ManualContingencyInvoiceStatus::PendingTranscription)<a class="btn btn-primary btn-sm" href="{{ route('billing.manual-cafc.transcribe.edit', $manual) }}">Transcribir</a>@endif</td></tr>
        @empty <x-ui.empty-row colspan="6" message="Todavía no se registraron facturas en este CAFC." /> @endforelse
    </tbody></table></div></div></div>
</div>
@endsection

@push('styles')<style>.c2-identity{border-top:4px solid #f59f00}.c2-identity .display-6{color:#183b56}</style>@endpush
@push('scripts')<script>document.addEventListener('DOMContentLoaded',()=>{const event=document.querySelector('[data-c2-event]');const description=document.querySelector('[data-c2-event-description]');const sync=()=>{if(description)description.value=event?.selectedOptions[0]?.dataset.description||''};event?.addEventListener('change',sync);sync();
const start=document.getElementById('cafc-event-start');
const end=document.getElementById('cafc-event-end');
const preview=document.querySelector('[data-c2-preview]');
const feedback=document.getElementById('c2-time-feedback');
const windows=[...document.querySelectorAll('[data-c2-cufd]')];
const format=value=>value.replace('T',' ');
const update=()=>{
    if(!start || !end)return;
    start.setCustomValidity('');end.setCustomValidity('');
    const startValue=start.value.length===16 ? start.value+':00' : start.value;
    const endValue=end.value.length===16 ? end.value+':00' : end.value;
    const reference=startValue || preview?.dataset.now;
    const selected=reference ? windows.find(row=>reference>=row.dataset.start && reference<row.dataset.end) : null;
    if(preview){
        preview.replaceChildren();
        const label=document.createElement('div');
        label.className='form-label';
        label.textContent=start.value ? (selected ? 'CUFD que se usará para el evento y las facturas' : 'No existe un CUFD vigente para este inicio') : 'Último CUFD disponible';
        preview.append(label);
        if(selected){
            const code=document.createElement('code');code.className='d-block text-break mb-2';code.textContent=selected.dataset.code;preview.append(code);
            const dates=document.createElement('div');dates.className='small';dates.textContent='Solicitado: '+selected.dataset.requested+' · Vencimiento: '+selected.dataset.expires;preview.append(dates);
            if(startValue){const period=document.createElement('div');period.className='small mt-2';period.textContent='Inicio permitido para este CUFD: desde '+selected.dataset.requested+' hasta antes de '+selected.dataset.limit+'.';preview.append(period)}
        }
        const hint=document.createElement('div');hint.className='small text-secondary mt-2';
        hint.textContent=startValue ? (selected ? 'CUFD seleccionado automáticamente según el inicio real del evento. La aceptación depende del SIN.' : 'Revise la fecha y hora reales y el historial de autorizaciones del punto de venta.') : (selected ? 'Ingrese el inicio real del evento. El CUFD se actualizará según esa fecha y hora.' : 'No hay un CUFD vigente en este momento. Ingrese el inicio real para comprobar el CUFD histórico.');
        preview.append(hint);
        if(start.value && !selected)start.setCustomValidity('No existe un CUFD vigente al inicio declarado. Revise la fecha y hora reales y las autorizaciones.');
    }
    let message='';
    if(start.value && end.value){
        if(endValue<=startValue){message='El fin debe ser posterior al inicio del evento.';end.setCustomValidity(message)}
        else if(end.validity.rangeUnderflow || end.validity.rangeOverflow)message='El fin debe estar dentro del horario permitido de las últimas 48 horas.';
        else message='Las facturas podrán registrarse desde '+format(start.value)+' hasta '+format(end.value)+'.';
    }
    if(feedback){feedback.textContent=message;feedback.className=end.validity.valid ? 'text-secondary small' : 'text-danger small'}
};
start?.addEventListener('input',update);end?.addEventListener('input',update);update();
});</script>@endpush
