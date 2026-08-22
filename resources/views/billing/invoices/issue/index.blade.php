@extends('layouts.admin')

@section('title', 'Emitir factura | '.config('app.name', 'Base Admin'))
@section('page-title', 'Emitir factura')
@section('page-subtitle', 'Tipos documento sector activos para facturacion')

@section('content')
    <x-ui.table-card title="Tipos documento sector activos">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Codigo</th>
                    <th>Documento sector</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documentSectors as $sector)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $sector->classifier_code }}</td>
                        <td>{{ $sector->description }}</td>
                        <td class="text-end">
                            <a class="btn btn-primary btn-sm" href="{{ route('billing.invoices.issue.show', $sector->classifier_code) }}">
                                <i class="ti ti-arrow-right me-1" aria-hidden="true"></i>Abrir
                            </a>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row colspan="3" message="No hay tipos documento sector activos." />
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
@endsection
