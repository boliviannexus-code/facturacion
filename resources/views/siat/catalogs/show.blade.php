@extends('layouts.admin')

@section('title', $catalog['name'].' | '.config('app.name', 'Base Admin'))
@section('page-title', $catalog['name'])
@section('page-subtitle', 'Datos sincronizados desde SIAT')

@section('content')
    <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center mb-3">
        <a class="btn btn-outline-secondary" href="{{ route('siat.catalogs.index') }}">
            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Catalogos
        </a>
        @can('siat-catalogs.sync')
            <form class="d-flex flex-column flex-sm-row gap-2" method="POST" action="{{ route('siat.catalogs.sync', $catalog['key']) }}">
                @csrf
                <div>
                    <select class="form-select @error('sin_point_of_sale_id') is-invalid @enderror" name="sin_point_of_sale_id" required>
                        <option value="">Sucursal / punto de venta</option>
                        @foreach ($pointOptions as $point)
                            <option value="{{ $point->id }}" @selected((string) old('sin_point_of_sale_id') === (string) $point->id)>
                                Sucursal {{ $point->branch->branch_code }} - {{ $point->branch->name }} / PV {{ $point->point_of_sale_code }} - {{ $point->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('sin_point_of_sale_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div>
                    <label class="form-label visually-hidden" for="catalog-sync-count">Veces</label>
                    <input
                        class="form-control @error('sync_count') is-invalid @enderror"
                        id="catalog-sync-count"
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
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>Sincronizar catalogo
                </button>
            </form>
        @endcan
    </div>

    <section class="card mb-3" aria-labelledby="catalog-detail-heading">
        <div class="card-header">
            <h3 class="card-title mb-0" id="catalog-detail-heading">Detalle del catalogo</h3>
        </div>
        <div class="card-body">
            <dl class="authorization-kv">
                <dt>Operacion</dt>
                <dd><code>{{ $catalog['operation'] }}</code></dd>

                @if ($catalog['hint'])
                    <dt>Contenido</dt>
                    <dd>{{ $catalog['hint'] }}</dd>
                @endif

                <dt>WSDL</dt>
                <dd>{{ $catalog['wsdl_url'] }}</dd>
            </dl>
        </div>
    </section>

    @can('siat-catalogs.sync')
        <form id="catalog-selected-status-form" method="POST" action="{{ route('siat.catalogs.items.status', $catalog['key']) }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="scope" value="selected">
        </form>
        <form id="catalog-all-active-form" method="POST" action="{{ route('siat.catalogs.items.status', $catalog['key']) }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="scope" value="all">
            <input type="hidden" name="is_active" value="1">
        </form>
        <form id="catalog-all-inactive-form" method="POST" action="{{ route('siat.catalogs.items.status', $catalog['key']) }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="scope" value="all">
            <input type="hidden" name="is_active" value="0">
        </form>
    @endcan

    <x-ui.table-card title="Datos sincronizados">
        @can('siat-catalogs.sync')
            <x-slot:actions>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-success btn-sm" form="catalog-selected-status-form" name="is_active" value="1" type="submit">
                        Activar seleccionados
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" form="catalog-selected-status-form" name="is_active" value="0" type="submit">
                        Desactivar seleccionados
                    </button>
                    <button class="btn btn-success btn-sm" form="catalog-all-active-form" type="submit">
                        Activar todos
                    </button>
                    <button class="btn btn-secondary btn-sm" form="catalog-all-inactive-form" type="submit">
                        Desactivar todos
                    </button>
                </div>
            </x-slot:actions>
        @endcan

        <table
            class="table table-hover align-middle mb-0"
            data-datatable
            data-url="{{ route('datatables.siat-catalog-items', $catalog['key']) }}"
            data-order='[[{{ auth()->user()?->can('siat-catalogs.sync') ? 2 : 0 }},"asc"]]'
            data-page-length="25"
            data-columns-id="catalog-items-table-columns"
        >
            <thead>
                <tr>
                    @can('siat-catalogs.sync')
                        <th class="w-1">
                            <input class="form-check-input" type="checkbox" aria-label="Seleccionar todos" data-catalog-select-all>
                        </th>
                        <th>Uso</th>
                    @endcan
                    <th>Codigo</th>
                    <th>Descripcion</th>
                    <th>Datos SIAT</th>
                    <th>Sincronizado</th>
                    <th>JSON</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <script type="application/json" id="catalog-items-table-columns">
            @can('siat-catalogs.sync')
                [
                    {"data":"selector","name":"selector","orderable":false,"searchable":false,"className":"w-1"},
                    {"data":"status","name":"is_active","searchable":false},
                    {"data":"code","name":"classifier_code"},
                    {"data":"description","name":"description","className":"fw-semibold"},
                    {"data":"raw_fields","name":"raw_fields","orderable":false},
                    {"data":"synced_at","name":"synced_at"},
                    {"data":"json","name":"json","orderable":false,"searchable":false}
                ]
            @else
                [
                    {"data":"code","name":"classifier_code"},
                    {"data":"description","name":"description","className":"fw-semibold"},
                    {"data":"raw_fields","name":"raw_fields","orderable":false},
                    {"data":"synced_at","name":"synced_at"},
                    {"data":"json","name":"json","orderable":false,"searchable":false}
                ]
            @endcan
        </script>
    </x-ui.table-card>

    @can('siat-catalogs.sync')
        <script>
            const catalogSelectAll = document.querySelector('[data-catalog-select-all]');

            catalogSelectAll?.addEventListener('change', (event) => {
                document.querySelectorAll('[data-catalog-item-selector]').forEach((checkbox) => {
                    checkbox.checked = event.target.checked;
                });
            });

            document.querySelector('[data-datatable]')?.addEventListener('draw.dt', () => {
                if (catalogSelectAll) {
                    catalogSelectAll.checked = false;
                }
            });
        </script>
    @endcan
@endsection
