@extends('layouts.admin')

@section('title', 'Facturas manuales CAFC | '.config('app.name'))
@section('page-title', 'Facturas manuales CAFC')
@section('page-subtitle', 'Consumo, anulación y transcripción de documentos físicos')

@section('content')
    <div class="alert alert-info" role="status">
        <div class="d-flex"><i class="ti ti-info-circle fs-2 me-2" aria-hidden="true"></i><div><strong>La fecha original queda bloqueada.</strong><div>Registre exactamente la fecha y hora escrita en la factura física. No podrá modificarse durante la transcripción.</div></div></div>
    </div>
    <div class="row g-3">
        @can('manual-cafc.use')
            <div class="col-12 col-xl-4">
                <x-ui.card title="Consumir número físico">
                    <form method="POST" action="{{ route('billing.manual-cafc.store') }}" class="row g-3" data-manual-register>
                        @csrf
                        <div class="col-12">
                            <label class="form-label" for="cafc_range_id">Rango CAFC</label>
                            <select class="form-select" id="cafc_range_id" name="cafc_range_id" required data-range-select>
                                <option value="">Seleccione</option>
                                @foreach ($ranges as $range)
                                    <option value="{{ $range->id }}" data-branch="{{ $range->sin_branch_id }}" data-point="{{ $range->sin_point_of_sale_id }}" data-next="{{ $range->next_number }}" @selected(old('cafc_range_id') == $range->id)>{{ $range->cafc_code }} · {{ $range->range_start }}–{{ $range->range_end }} · {{ $range->remaining_count }} libres</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="sin_point_of_sale_id">Punto de venta</label>
                            <select class="form-select" id="sin_point_of_sale_id" name="sin_point_of_sale_id" required data-point-select>
                                <option value="">Seleccione</option>
                                @foreach ($points as $point)
                                    <option value="{{ $point->id }}" data-branch="{{ $point->sin_branch_id }}" @selected(old('sin_point_of_sale_id') == $point->id)>{{ $point->branch->display_name }} · {{ $point->display_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="significant_event_id">Evento significativo</label>
                            <select class="form-select" id="significant_event_id" name="significant_event_id" required data-event-select>
                                <option value="">Seleccione</option>
                                @foreach ($events as $event)
                                    <option value="{{ $event->id }}" data-point="{{ $event->sin_point_of_sale_id }}" @selected(old('significant_event_id') == $event->id)>#{{ $event->id }} · {{ $event->event_description }} · {{ $event->started_at->format('d/m/Y H:i') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="manual_invoice_number">Número manual</label>
                            <input class="form-control" type="number" min="1" id="manual_invoice_number" name="manual_invoice_number" value="{{ old('manual_invoice_number') }}" required data-number-input>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="operation">Operación</label>
                            <select class="form-select" id="operation" name="operation" required data-operation>
                                <option value="used" @selected(old('operation') === 'used')>Utilizada</option>
                                <option value="cancelled" @selected(old('operation') === 'cancelled')>Anulada</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="issued_manually_at">Fecha y hora original</label>
                            <input class="form-control" type="datetime-local" step="1" id="issued_manually_at" name="issued_manually_at" value="{{ old('issued_manually_at') }}" required>
                        </div>
                        <div class="col-12 d-none" data-void-reason>
                            <label class="form-label" for="void_reason">Motivo de anulación</label>
                            <textarea class="form-control" id="void_reason" name="void_reason" rows="2">{{ old('void_reason') }}</textarea>
                        </div>
                        <div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Registrar número</button></div>
                    </form>
                </x-ui.card>
            </div>
        @endcan
        <div class="col-12 @can('manual-cafc.use') col-xl-8 @endcan">
            <x-ui.table-card title="Documentos físicos registrados">
                <x-slot:actions><a class="btn btn-outline-primary btn-sm" href="{{ route('billing.cafc-ranges.index') }}">Administrar rangos</a></x-slot:actions>
                <table class="table table-vcenter">
                    <thead><tr><th>Documento</th><th>Fecha original</th><th>Punto de venta</th><th>Estado</th><th class="text-end">Acción</th></tr></thead>
                    <tbody>
                    @forelse ($manualInvoices as $manual)
                        <tr>
                            <td><div class="fw-semibold">N.º {{ $manual->manual_invoice_number }}</div><div class="text-secondary small">CAFC {{ $manual->cafcRange->cafc_code }}</div></td>
                            <td class="text-nowrap">{{ $manual->issued_manually_at->format('d/m/Y H:i:s') }}</td>
                            <td>{{ $manual->pointOfSale->display_name }}</td>
                            <td><span class="badge {{ $manual->manual_status === \App\Enums\ManualContingencyInvoiceStatus::Cancelled ? 'bg-danger-lt' : 'bg-blue-lt' }}">{{ $manual->manual_status->label() }}</span></td>
                            <td class="text-end">
                                @if ($manual->manual_status === \App\Enums\ManualContingencyInvoiceStatus::PendingTranscription)
                                    @can('manual-cafc.transcribe')<a class="btn btn-primary btn-sm" href="{{ route('billing.manual-cafc.transcribe.edit', $manual) }}">Transcribir</a>@endcan
                                @elseif ($manual->manual_status === \App\Enums\ManualContingencyInvoiceStatus::PendingSend)
                                    @can('manual-cafc.transcribe')<form method="POST" action="{{ route('billing.manual-cafc.send', $manual) }}" class="d-inline">@csrf<button class="btn btn-outline-primary btn-sm">Reintentar envío</button></form>@endcan
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-row colspan="5" message="No se registraron facturas físicas." />
                    @endforelse
                    </tbody>
                </table>
                <x-slot:footer>{{ $manualInvoices->links() }}</x-slot:footer>
            </x-ui.table-card>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const range = document.querySelector('[data-range-select]');
    const point = document.querySelector('[data-point-select]');
    const event = document.querySelector('[data-event-select]');
    const number = document.querySelector('[data-number-input]');
    const operation = document.querySelector('[data-operation]');
    const voidBox = document.querySelector('[data-void-reason]');
    const update = () => {
        const selected = range?.selectedOptions[0];
        if (selected?.dataset.next && !number.value) number.value = selected.dataset.next;
        Array.from(point?.options || []).forEach((option, i) => option.hidden = i > 0 && selected?.dataset.branch && option.dataset.branch !== selected.dataset.branch);
        Array.from(event?.options || []).forEach((option, i) => option.hidden = i > 0 && point.value && option.dataset.point !== point.value);
        voidBox?.classList.toggle('d-none', operation?.value !== 'cancelled');
        const reason = voidBox?.querySelector('textarea'); if (reason) reason.required = operation?.value === 'cancelled';
    };
    range?.addEventListener('change', update); point?.addEventListener('change', update); operation?.addEventListener('change', update); update();
});
</script>
@endpush
