@extends('layouts.admin')

@section('title', 'Sucursales SIAT | '.config('app.name', 'Base Admin'))
@section('page-title', 'Sucursales SIAT')
@section('page-subtitle', 'Registro y consulta de puntos de venta mediante los servicios SOAP del SIN')

@section('content')
    @can('siat-branches.manage')
        <x-ui.form-panel :action="route('siat.branches.store')" method="POST">
            <section class="authorization-form-section" aria-labelledby="branch-create-heading">
                <div class="authorization-form-section-header">
                    <span class="authorization-form-section-icon" aria-hidden="true"><i class="ti ti-building-store"></i></span>
                    <div>
                        <h2 class="authorization-form-section-title" id="branch-create-heading">Nueva sucursal</h2>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-3">
                        <label class="form-label" for="branch-code">Numero sucursal</label>
                        <input
                            class="form-control @error('branch_code') is-invalid @enderror"
                            id="branch-code"
                            name="branch_code"
                            type="number"
                            min="0"
                            step="1"
                            value="{{ old('branch_code') }}"
                            required
                        >
                        <div class="form-hint">Casa matriz usa 0.</div>
                        @error('branch_code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label" for="branch-name">Nombre</label>
                        <input
                            class="form-control @error('name') is-invalid @enderror"
                            id="branch-name"
                            name="name"
                            type="text"
                            maxlength="255"
                            value="{{ old('name') }}"
                            required
                        >
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-lg-3 d-flex align-items-end">
                        <label class="form-check form-switch mb-2">
                            <input class="form-check-input @error('is_main') is-invalid @enderror" type="checkbox" name="is_main" value="1" @checked(old('is_main'))>
                            <span class="form-check-label">Casa matriz</span>
                        </label>
                        @error('is_main')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </section>

            <div class="d-flex justify-content-end mt-4">
                <button class="btn btn-primary" type="submit">
                    <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Guardar sucursal
                </button>
            </div>
        </x-ui.form-panel>
    @endcan

    <div class="mt-3">
        <x-ui.table-card title="Sucursales registradas">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sucursal</th>
                        <th>Tipo</th>
                        <th>Puntos de venta informados por SIAT</th>
                        <th>Operaciones SIN</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $branch)
                        <tr>
                            <td>
                                <div class="fw-semibold">#{{ $branch->branch_code }} - {{ $branch->name }}</div>
                                <div class="text-body-secondary small">{{ $branch->is_active ? 'Activa' : 'Inactiva' }}</div>
                            </td>
                            <td>
                                @if ($branch->is_main)
                                    <span class="badge bg-primary-lt">Casa matriz</span>
                                @else
                                    <span class="badge bg-secondary-lt">Sucursal</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    @foreach ($branch->pointsOfSale as $point)
                                        <div class="border rounded px-2 py-1">
                                            <span class="badge {{ $point->is_default ? 'bg-success-lt' : 'bg-secondary-lt' }}">
                                                PV {{ $point->point_of_sale_code }}
                                            </span>
                                            <span class="ms-1">{{ $point->name }}</span>
                                            @if ($point->point_of_sale_type)
                                                <div class="small text-body-secondary mt-1">{{ $point->point_of_sale_type }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @can('siat-branches.manage')
                                    <form class="mb-2" method="POST" action="{{ route('siat.branches.points.synchronize', $branch) }}">
                                        @csrf
                                        <button class="btn btn-outline-secondary btn-sm w-100" type="submit">
                                            <i class="ti ti-database-import me-1" aria-hidden="true"></i>Recuperar puntos de Impuestos
                                        </button>
                                        <div class="form-hint mt-1">Importa puntos faltantes y actualiza los existentes.</div>
                                    </form>
                                    <form method="POST" action="{{ route('siat.branches.points.store', $branch) }}">
                                        @csrf
                                        <div class="row g-2">
                                            <div class="col-12">
                                                <select class="form-select form-select-sm @error('point_of_sale_type_code') is-invalid @enderror" name="point_of_sale_type_code" required>
                                                    <option value="">Tipo de punto de venta</option>
                                                    @foreach ($pointOfSaleTypes as $code => $label)
                                                        <option value="{{ $code }}" @selected((string) old('point_of_sale_type_code') === (string) $code)>{{ $code }}. {{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <input class="form-control form-control-sm @error('name') is-invalid @enderror" name="name" type="text" maxlength="255" value="{{ old('name') }}" placeholder="Nombre asignado al punto" required>
                                            </div>
                                            <div class="col-12">
                                                <input class="form-control form-control-sm @error('description') is-invalid @enderror" name="description" type="text" maxlength="255" value="{{ old('description') }}" placeholder="Descripción para el SIN" required>
                                            </div>
                                            <div class="col-12">
                                                <button class="btn btn-primary btn-sm w-100" type="submit">
                                                    <i class="ti ti-cloud-upload me-1" aria-hidden="true"></i>Registrar en SIN
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                @else
                                    -
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-row colspan="4" message="No hay sucursales registradas." />
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    </div>
@endsection
