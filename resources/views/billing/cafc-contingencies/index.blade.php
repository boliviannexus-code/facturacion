@extends('layouts.admin')

@section('title', 'Contingencias 2 | '.config('app.name'))
@section('page-title', 'Contingencias 2')
@section('page-subtitle', 'Eventos 5, 6 y 7 · transcripción de facturas autorizadas por CAFC')

@section('content')
<div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between gap-3 mb-3">
    <div><span class="badge bg-orange-lt mb-2">Circuito CAFC</span><h2 class="h3 mb-1">Autorizaciones disponibles</h2><p class="text-secondary mb-0">Ingresa a un CAFC para registrar el evento y transcribir sus facturas.</p></div>
    @can('cafc-ranges.manage')<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCafcModal"><i class="ti ti-plus me-1"></i>Nuevo CAFC</button>@endcan
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>CAFC / evento SIN</th><th>Sector</th><th>Asignación</th><th>Numeración</th><th>Disponibles</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            @forelse($ranges as $range)
                <tr>
                    <td><a class="fw-semibold" href="{{ route('billing.cafc-contingencies.show', $range) }}">{{ $range->cafc_code }}</a><div class="small text-secondary">{{ $range->authorized_from->format('d/m/Y') }} – {{ $range->authorized_until->format('d/m/Y') }}</div>@if($range->significantEvent)<div class="small text-success">Evento {{ $range->significantEvent->event_code }} · recepción {{ $range->significantEvent->reception_code ?? 'registrada' }}</div>@else<div class="small text-warning">Transcripción abierta · evento pendiente</div>@endif</td>
                    <td>{{ \App\Services\Billing\InvoiceDocumentSector::supports((int) $range->document_sector_code) ? \App\Services\Billing\InvoiceDocumentSector::title((int) $range->document_sector_code) : 'Sector documental '.$range->document_sector_code }}<div class="small text-secondary">Sector {{ $range->document_sector_code }}</div></td>
                    <td>{{ $range->branch->display_name }}<div class="small text-secondary">{{ $range->pointOfSale?->display_name ?? 'Toda la sucursal' }}</div></td>
                    <td>{{ number_format($range->range_start) }} – {{ number_format($range->range_end) }}<div class="small text-secondary">Siguiente {{ number_format($range->next_number) }}</div></td>
                    <td><strong>{{ $range->remaining_count }}</strong></td>
                    <td><span class="badge {{ $range->range_status->canConsume() ? 'bg-success-lt' : 'bg-secondary-lt' }}">{{ $range->range_status->label() }}</span></td>
                    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="{{ route('billing.cafc-contingencies.show', $range) }}">Abrir <i class="ti ti-chevron-right ms-1"></i></a></td>
                </tr>
            @empty
                <x-ui.empty-row colspan="7" message="No existen autorizaciones CAFC." />
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $ranges->links() }}</div>
</div>

@can('cafc-ranges.manage')
<div class="modal modal-blur fade" id="newCafcModal" tabindex="-1" aria-labelledby="newCafcTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"><form class="modal-content" method="POST" action="{{ route('billing.cafc-contingencies.store') }}">@csrf
        <div class="modal-header"><div><span class="text-uppercase text-primary small fw-bold">Nueva autorización</span><h2 class="modal-title" id="newCafcTitle">Registrar CAFC</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Código CAFC</label><input class="form-control" name="cafc_code" value="{{ old('cafc_code') }}" required maxlength="128"></div>
            <div class="col-md-6"><label class="form-label">Sector del documento</label><select class="form-select" name="document_sector_code" required><option value="">Seleccionar</option>@foreach($sectors as $sector)<option value="{{ $sector->classifier_code }}">{{ $sector->classifier_code }} · {{ $sector->description }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">Sucursal</label><select class="form-select" name="sin_branch_id" required data-c2-branch><option value="">Seleccionar</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->display_name }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">Punto de venta</label><select class="form-select" name="sin_point_of_sale_id" required data-c2-point><option value="">Seleccionar</option>@foreach($branches as $branch)@foreach($branch->activePointsOfSale as $point)<option value="{{ $point->id }}" data-branch="{{ $branch->id }}">{{ $point->display_name }}</option>@endforeach @endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Número inicial</label><input class="form-control" type="number" min="1" name="range_start" value="{{ old('range_start', 1) }}" required></div>
            <div class="col-md-3"><label class="form-label">Número final</label><input class="form-control" type="number" min="1" name="range_end" required></div>
            <div class="col-md-3"><label class="form-label">Autorizado desde</label><input class="form-control" type="date" name="authorized_from" required></div>
            <div class="col-md-3"><label class="form-label">Límite de emisión</label><input class="form-control" type="date" name="authorized_until" required></div>
            <div class="col-12"><label class="form-label">Observaciones</label><textarea class="form-control" name="notes" rows="2" maxlength="1000"></textarea></div>
        </div></div>
        <div class="modal-footer"><button class="btn btn-link" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Registrar y abrir</button></div>
    </form></div>
</div>
@endcan
@endsection

@push('scripts')<script>document.addEventListener('DOMContentLoaded',()=>{const branch=document.querySelector('[data-c2-branch]');const point=document.querySelector('[data-c2-point]');const filter=()=>[...(point?.options||[])].forEach((option,index)=>{option.hidden=index>0&&option.dataset.branch!==branch?.value;if(option.selected&&option.hidden)point.value='' });branch?.addEventListener('change',filter);filter()});</script>@endpush
