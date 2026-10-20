@extends('layouts.admin')

@section('title', 'Servicios WSDL | '.config('app.name', 'Base Admin'))
@section('page-title', 'Servicios WSDL del SIN')
@section('page-subtitle', 'Administra los enlaces SOAP disponibles para infraestructura y documentos sector')

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="GET" action="{{ route('siat.wsdl-services.index') }}" class="mb-4">
        <label class="form-label" for="wsdl-environment-filter">Ambiente de los servicios</label>
        <div class="d-flex gap-2">
            <select class="form-select" id="wsdl-environment-filter" name="environment_code">
                <option value="2" @selected((string) $selectedEnvironment === '2')>Pruebas y Piloto</option>
                <option value="1" @selected((string) $selectedEnvironment === '1')>Producción</option>
                <option value="unclassified" @selected($selectedEnvironment === 'unclassified')>Sin clasificar</option>
            </select>
            <button class="btn btn-outline-primary" type="submit">Ver servicios</button>
        </div>
        <p class="form-hint">La autorización SIN determina el ambiente fiscal. Este filtro solo selecciona los servicios que estás administrando. Los servicios sin clasificar no se utilizan.</p>
    </form>

    @can('sin-api-tokens.manage')
        <x-ui.form-panel :action="route('siat.wsdl-services.store')" method="POST">
            <section class="authorization-form-section" aria-labelledby="wsdl-create-heading">
                <div class="authorization-form-section-header">
                    <span class="authorization-form-section-icon" aria-hidden="true"><i class="ti ti-link-plus"></i></span>
                    <div>
                        <h2 class="authorization-form-section-title" id="wsdl-create-heading">Agregar servicio WSDL</h2>
                        <p class="text-body-secondary mb-0">El servicio se utilizará solo para esta empresa y el ambiente seleccionado. Producción requiere activación explícita.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label" for="wsdl-environment">Ambiente</label>
                        <select id="wsdl-environment" name="environment_code" class="form-select @error('environment_code') is-invalid @enderror" required>
                            @foreach (\App\Enums\SiatEnvironment::options() as $code => $label)
                                <option value="{{ $code }}" @selected((string) old('environment_code', $selectedEnvironment) === (string) $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('environment_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label" for="wsdl-key">Clave interna</label>
                        <input class="form-control @error('key') is-invalid @enderror" id="wsdl-key" name="key" value="{{ old('key') }}" placeholder="factura_tasa_cero" maxlength="100" required>
                        <div class="form-hint">Minúsculas, números y guion bajo.</div>
                    </div>
                    <div class="col-lg-5">
                        <label class="form-label" for="wsdl-name">Nombre</label>
                        <input class="form-control @error('name') is-invalid @enderror" id="wsdl-name" name="name" value="{{ old('name') }}" maxlength="255" required>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="wsdl-category">Categoría</label>
                        <select class="form-select @error('category') is-invalid @enderror" id="wsdl-category" name="category" required>
                            <option value="infraestructura" @selected(old('category') === 'infraestructura')>Infraestructura SIAT</option>
                            <option value="facturacion" @selected(old('category', 'facturacion') === 'facturacion')>Facturación / documento sector</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="wsdl-url">URL WSDL</label>
                        <input class="form-control @error('url') is-invalid @enderror" id="wsdl-url" name="url" type="url" value="{{ old('url') }}" placeholder="https://.../ServicioFacturacion...?wsdl" maxlength="1000" required>
                    </div>
                    <div class="col-lg-9">
                        <label class="form-label" for="wsdl-description">Descripción</label>
                        <input class="form-control @error('description') is-invalid @enderror" id="wsdl-description" name="description" value="{{ old('description') }}" maxlength="1000">
                    </div>
                    <div class="col-lg-3 d-flex align-items-end">
                        <label class="form-check form-switch mb-2">
                            <input class="form-check-input" name="is_active" type="checkbox" value="1" @checked((string) $selectedEnvironment === '2')>
                            <span class="form-check-label">Servicio activo</span>
                        </label>
                    </div>
                </div>
            </section>

            <div class="d-flex justify-content-end mt-4">
                <button class="btn btn-primary" type="submit"><i class="ti ti-plus me-1"></i>Agregar WSDL</button>
            </div>
        </x-ui.form-panel>
    @endcan

    <div class="row g-3 mt-1">
        @forelse ($services as $service)
            <div class="col-12">
                <section class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-1">{{ $service->name }}</h3>
                            <span class="badge bg-secondary-lt">{{ $service->environment_code?->label() ?? 'Sin clasificar' }}</span>
                            <span class="badge {{ $service->category === 'facturacion' ? 'bg-blue-lt' : 'bg-azure-lt' }}">{{ ucfirst($service->category) }}</span>
                            <span class="badge {{ $service->is_active ? 'bg-success-lt' : 'bg-secondary-lt' }}">{{ $service->is_active ? 'Activo' : 'Inactivo' }}</span>
                        </div>
                    </div>
                    <div class="card-body">
                        @can('sin-api-tokens.manage')
                            <form method="POST" action="{{ route('siat.wsdl-services.update', $service) }}">
                                @csrf
                                @method('PUT')
                                <div class="row g-3">
                                    <div class="col-lg-4"><label class="form-label" for="service-environment-{{ $service->id }}">Ambiente</label><select id="service-environment-{{ $service->id }}" class="form-select" name="environment_code" required>
                                        <option value="">Selecciona un ambiente</option>
                                        @foreach (\App\Enums\SiatEnvironment::options() as $code => $label)
                                            <option value="{{ $code }}" @selected($service->environment_code?->value === $code)>{{ $label }}</option>
                                        @endforeach
                                    </select></div>
                                    <div class="col-lg-3"><label class="form-label">Clave</label><input class="form-control" name="key" value="{{ $service->key }}" maxlength="100" required></div>
                                    <div class="col-lg-5"><label class="form-label">Nombre</label><input class="form-control" name="name" value="{{ $service->name }}" maxlength="255" required></div>
                                    <div class="col-lg-4"><label class="form-label">Categoría</label><select class="form-select" name="category"><option value="infraestructura" @selected($service->category === 'infraestructura')>Infraestructura SIAT</option><option value="facturacion" @selected($service->category === 'facturacion')>Facturación / documento sector</option></select></div>
                                    <div class="col-12"><label class="form-label">URL WSDL</label><input class="form-control font-monospace" name="url" type="url" value="{{ $service->url }}" maxlength="1000" required></div>
                                    <div class="col-lg-9"><label class="form-label">Descripción</label><input class="form-control" name="description" value="{{ $service->description }}" maxlength="1000"></div>
                                    <div class="col-lg-3 d-flex align-items-end"><label class="form-check form-switch mb-2"><input class="form-check-input" name="is_active" type="checkbox" value="1" @checked($service->is_active)><span class="form-check-label">Activo</span></label></div>
                                </div>
                                <div class="d-flex justify-content-end mt-3"><button class="btn btn-outline-primary" type="submit"><i class="ti ti-device-floppy me-1"></i>Guardar cambios</button></div>
                            </form>
                            <form class="d-flex justify-content-end mt-2" method="POST" action="{{ route('siat.wsdl-services.destroy', $service) }}" data-confirm-delete="¿Eliminar el servicio WSDL {{ $service->name }}?">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="ti ti-trash me-1"></i>Eliminar</button>
                            </form>
                        @else
                            <dl class="authorization-kv mb-0"><dt>Clave</dt><dd>{{ $service->key }}</dd><dt>URL</dt><dd class="text-break">{{ $service->url }}</dd><dt>Descripción</dt><dd>{{ $service->description ?: '-' }}</dd></dl>
                        @endcan
                    </div>
                </section>
            </div>
        @empty
            <div class="col-12"><div class="alert alert-info">No existen servicios WSDL registrados.</div></div>
        @endforelse
    </div>
@endsection
