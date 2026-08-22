@extends('layouts.admin')

@section('title', 'Rangos CAFC | '.config('app.name'))
@section('page-title', 'Rangos CAFC')
@section('page-subtitle', 'Autorizaciones para facturas manuales de contingencia')

@section('content')
    <div class="row g-3">
        @can('cafc-ranges.manage')
            <div class="col-12 col-xl-4">
                <x-ui.card title="Registrar autorización">
                    <form method="POST" action="{{ route('billing.cafc-ranges.store') }}" class="row g-3" data-cafc-range-form>
                        @csrf
                        <div class="col-12">
                            <label class="form-label" for="cafc_code">CAFC</label>
                            <input class="form-control @error('cafc_code') is-invalid @enderror" id="cafc_code" name="cafc_code" value="{{ old('cafc_code') }}" required maxlength="128" autocomplete="off">
                            @error('cafc_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="sin_branch_id">Sucursal</label>
                            <select class="form-select" id="sin_branch_id" name="sin_branch_id" required data-branch-select>
                                <option value="">Seleccione</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(old('sin_branch_id') == $branch->id)>{{ $branch->display_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="sin_point_of_sale_id">Punto de venta <span class="text-secondary">(opcional)</span></label>
                            <select class="form-select" id="sin_point_of_sale_id" name="sin_point_of_sale_id" data-point-select>
                                <option value="">Toda la sucursal</option>
                                @foreach ($branches as $branch)
                                    @foreach ($branch->activePointsOfSale as $point)
                                        <option value="{{ $point->id }}" data-branch="{{ $branch->id }}" @selected(old('sin_point_of_sale_id') == $point->id)>{{ $point->display_name }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                            <div class="form-hint">Sin punto de venta, el rango podrá usarse en cualquier PV de la sucursal.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="range_start">Número inicial</label>
                            <input class="form-control" type="number" min="1" id="range_start" name="range_start" value="{{ old('range_start', 1) }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="range_end">Número final</label>
                            <input class="form-control" type="number" min="1" id="range_end" name="range_end" value="{{ old('range_end') }}" required>
                        </div>
                        <input type="hidden" name="document_sector_code" value="1">
                        <div class="col-6">
                            <label class="form-label" for="authorized_from">Autorizado desde</label>
                            <input class="form-control" type="date" id="authorized_from" name="authorized_from" value="{{ old('authorized_from') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="authorized_until">Límite de emisión</label>
                            <input class="form-control" type="date" id="authorized_until" name="authorized_until" value="{{ old('authorized_until') }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="notes">Observaciones</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                        </div>
                        <div class="col-12 d-grid">
                            <button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Registrar rango</button>
                        </div>
                    </form>
                </x-ui.card>
            </div>
        @endcan

        <div class="col-12 @can('cafc-ranges.manage') col-xl-8 @endcan">
            <x-ui.table-card title="Autorizaciones registradas">
                <x-slot:actions>
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('billing.manual-cafc.index') }}">Facturas manuales</a>
                </x-slot:actions>
                <table class="table table-vcenter">
                    <thead><tr><th>CAFC / asignación</th><th>Numeración</th><th>Uso</th><th>Vigencia</th><th>Estado</th></tr></thead>
                    <tbody>
                    @forelse ($ranges as $range)
                        <tr>
                            <td><div class="fw-semibold">{{ $range->cafc_code }}</div><div class="text-secondary small">{{ $range->branch->display_name }} · {{ $range->pointOfSale?->display_name ?? 'Toda la sucursal' }}</div></td>
                            <td><div class="fw-semibold">{{ number_format($range->range_start) }} — {{ number_format($range->range_end) }}</div><div class="text-secondary small">Siguiente: {{ number_format($range->next_number) }}</div></td>
                            <td style="min-width: 170px">
                                <div class="d-flex justify-content-between small mb-1"><span>{{ $range->used_count }} usadas</span><span>{{ $range->cancelled_count }} anuladas</span></div>
                                <div class="progress progress-sm" aria-label="Uso del rango CAFC"><div class="progress-bar" style="width: {{ min(100, (($range->used_count + $range->cancelled_count) / max(1, $range->range_end - $range->range_start + 1)) * 100) }}%"></div></div>
                                <div class="text-secondary small mt-1">{{ $range->remaining_count }} disponibles</div>
                            </td>
                            <td class="text-nowrap">{{ $range->authorized_from->format('d/m/Y') }}<br><span class="text-secondary">hasta {{ $range->authorized_until->format('d/m/Y') }}</span></td>
                            <td><span class="badge {{ in_array($range->range_status->value, ['AVAILABLE','IN_USE']) ? 'bg-success-lt' : 'bg-secondary-lt' }}">{{ $range->range_status->label() }}</span></td>
                        </tr>
                    @empty
                        <x-ui.empty-row colspan="5" message="No hay rangos CAFC registrados." />
                    @endforelse
                    </tbody>
                </table>
                <x-slot:footer>{{ $ranges->links() }}</x-slot:footer>
            </x-ui.table-card>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const branch = document.querySelector('[data-branch-select]');
    const point = document.querySelector('[data-point-select]');
    if (!branch || !point) return;
    const filter = () => Array.from(point.options).forEach((option, index) => {
        option.hidden = index > 0 && option.dataset.branch !== branch.value;
        if (option.selected && option.hidden) point.value = '';
    });
    branch.addEventListener('change', filter);
    filter();
});
</script>
@endpush
