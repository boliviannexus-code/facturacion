@extends('layouts.admin')

@section('title', 'Catalogos SIAT | '.config('app.name', 'Base Admin'))
@section('page-title', 'Catalogos SIAT')
@section('page-subtitle', 'Sincronizacion y consulta de catalogos por empresa')

@section('content')
    <x-ui.table-card title="Catalogos disponibles">
        @can('siat-catalogs.sync')
            <x-slot:actions>
                <form class="d-flex flex-column flex-sm-row gap-2" method="POST" action="{{ route('siat.catalogs.sync-all') }}">
                    @csrf
                    <div>
                        <label class="visually-hidden" for="sync-all-point-of-sale">Sucursal / punto de venta</label>
                        <select
                            class="form-select form-select-sm @error('sin_point_of_sale_id') is-invalid @enderror"
                            id="sync-all-point-of-sale"
                            name="sin_point_of_sale_id"
                            required
                        >
                            <option value="">Sucursal / PV</option>
                            @foreach ($pointOptions as $point)
                                <option value="{{ $point->id }}" @selected((string) old('sin_point_of_sale_id') === (string) $point->id)>
                                    Suc. {{ $point->branch->branch_code }} / PV {{ $point->point_of_sale_code }}
                                </option>
                            @endforeach
                        </select>
                        @error('sin_point_of_sale_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <input name="sync_count" type="hidden" value="1">
                    <button class="btn btn-primary btn-sm" type="submit" @disabled($pointOptions->isEmpty())>
                        <i class="ti ti-refresh me-1" aria-hidden="true"></i>Sincronizar todos
                    </button>
                </form>
            </x-slot:actions>
        @endcan

        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Catalogo</th>
                    <th>Registros</th>
                    <th>Ultima sincronizacion</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($catalogs as $catalog)
                    @php($lastSync = $catalog['last_sync'])
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $catalog['name'] }}</div>
                            @if ($catalog['hint'])
                                <div class="text-body-secondary small">{{ $catalog['hint'] }}</div>
                            @endif
                        </td>
                        <td>{{ $catalog['items_count'] }}</td>
                        <td>{{ $lastSync?->synced_at?->format('d/m/Y H:i') ?? '-' }}</td>
                        <td>
                            @if ($lastSync)
                                <span class="badge {{ $lastSync->status_badge }}">{{ $lastSync->status_label }}</span>
                            @else
                                <span class="badge bg-secondary-lt">Pendiente</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-2">
                                <a class="btn btn-outline-primary btn-sm" href="{{ route('siat.catalogs.show', $catalog['key']) }}">
                                    <i class="ti ti-eye me-1" aria-hidden="true"></i>Ver datos
                                </a>
                                @can('siat-catalogs.sync')
                                    <form class="d-inline-flex gap-2 align-items-start" method="POST" action="{{ route('siat.catalogs.sync', $catalog['key']) }}">
                                        @csrf
                                        <div>
                                            <select class="form-select form-select-sm @error('sin_point_of_sale_id') is-invalid @enderror" name="sin_point_of_sale_id" required>
                                                <option value="">Sucursal / PV</option>
                                                @foreach ($pointOptions as $point)
                                                    <option value="{{ $point->id }}" @selected((string) old('sin_point_of_sale_id') === (string) $point->id)>
                                                        Suc. {{ $point->branch->branch_code }} / PV {{ $point->point_of_sale_code }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('sin_point_of_sale_id')
                                                <div class="invalid-feedback text-start">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div>
                                            <input
                                                class="form-control form-control-sm @error('sync_count') is-invalid @enderror"
                                                name="sync_count"
                                                type="number"
                                                min="1"
                                                max="50"
                                                step="1"
                                                value="{{ old('sync_count', 1) }}"
                                                aria-label="Cantidad de sincronizaciones"
                                                required
                                            >
                                            @error('sync_count')
                                                <div class="invalid-feedback text-start">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <button class="btn btn-primary btn-sm" type="submit">
                                            <i class="ti ti-refresh me-1" aria-hidden="true"></i>Sincronizar
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.table-card>
@endsection
