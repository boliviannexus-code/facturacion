@php
    $product ??= null;
    $selectedCategory = old('product_category_id', $product?->product_category_id);
    $selectedMeasurementUnit = old('measurement_unit_code', $product?->measurement_unit_code);
    $selectedEconomicActivity = old('economic_activity_code', $product?->economic_activity_code);
    $selectedSiatProductCode = old('siat_product_code', $product?->siat_product_code);
@endphp

<x-ui.form-panel :action="$action" :method="$method">
    <section class="product-form-section" aria-labelledby="product-commercial-heading">
        <div class="product-form-section-header">
            <span class="product-form-section-icon" aria-hidden="true"><i class="ti ti-tag"></i></span>
            <div>
                <h2 class="product-form-section-title" id="product-commercial-heading">Identificacion comercial</h2>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <label class="form-label" for="product-internal-code">Codigo interno</label>
                <input
                    class="form-control @error('internal_code') is-invalid @enderror"
                    id="product-internal-code"
                    name="internal_code"
                    type="text"
                    maxlength="120"
                    value="{{ old('internal_code', $product?->internal_code) }}"
                    aria-describedby="product-internal-code-help"
                    required
                    autofocus
                >
                <div class="form-hint" id="product-internal-code-help">Se enviara al XML como codigoProducto.</div>
                @error('internal_code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-8">
                <label class="form-label" for="product-description">Descripcion</label>
                <input
                    class="form-control @error('description') is-invalid @enderror"
                    id="product-description"
                    name="description"
                    type="text"
                    maxlength="500"
                    value="{{ old('description', $product?->description) }}"
                    required
                >
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-6">
                <label class="form-label" for="product-category">Categoria</label>
                <select
                    class="form-select @error('product_category_id') is-invalid @enderror"
                    id="product-category"
                    name="product_category_id"
                    data-tom-select
                    data-placeholder="Buscar categoria"
                    required
                >
                    <option value="">Seleccionar categoria</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $selectedCategory === (string) $category->id)>
                            {{ $category->name }}{{ $category->is_active ? '' : ' (inactiva)' }}
                        </option>
                    @endforeach
                </select>
                @error('product_category_id')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                @if ($categories->isEmpty())
                    <div class="form-hint text-warning">Registra una categoria activa antes de guardar el producto.</div>
                @endif
            </div>

            <div class="col-lg-6">
                <label class="form-label" for="product-measurement-unit">Unidad de medida</label>
                <select
                    class="form-select @error('measurement_unit_code') is-invalid @enderror"
                    id="product-measurement-unit"
                    name="measurement_unit_code"
                    data-tom-select
                    data-allow-empty-option="false"
                    data-placeholder="Buscar unidad SIAT"
                    required
                >
                    <option value="" disabled>Seleccionar unidad</option>
                    @foreach ($siatMeasurementUnits as $measurementUnit)
                        <option value="{{ $measurementUnit['code'] }}" @selected((string) $selectedMeasurementUnit === (string) $measurementUnit['code'])>
                            {{ $measurementUnit['code'] }} - {{ $measurementUnit['description'] }}{{ $measurementUnit['is_active'] ? '' : ' (inactiva)' }}
                        </option>
                    @endforeach
                </select>
                @error('measurement_unit_code')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                @if ($siatMeasurementUnits->isEmpty())
                    <div class="form-hint text-warning">Sincroniza el catalogo SIAT de unidades de medida antes de guardar productos.</div>
                @endif
            </div>
        </div>
    </section>

    <section class="product-form-section" aria-labelledby="product-siat-heading" data-product-siat-form>
        <div class="product-form-section-header">
            <span class="product-form-section-icon product-form-section-icon-siat" aria-hidden="true"><i class="ti ti-file-invoice"></i></span>
            <div>
                <h2 class="product-form-section-title" id="product-siat-heading">Homologacion SIN</h2>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-5">
                <label class="form-label" for="product-economic-activity">Actividad economica</label>
                <select
                    class="form-select @error('economic_activity_code') is-invalid @enderror"
                    id="product-economic-activity"
                    name="economic_activity_code"
                    data-tom-select
                    data-allow-empty-option="false"
                    data-product-siat-activity
                    data-placeholder="Buscar actividad economica"
                    aria-describedby="product-economic-activity-help"
                    required
                >
                    <option value="" disabled>Seleccionar actividad</option>
                    @foreach ($siatActivities as $activity)
                        <option value="{{ $activity['code'] }}" @selected((string) $selectedEconomicActivity === (string) $activity['code'])>
                            {{ $activity['code'] }} - {{ $activity['description'] }}{{ $activity['is_active'] ? '' : ' (inactiva)' }}
                        </option>
                    @endforeach
                </select>
                <div class="form-hint" id="product-economic-activity-help">Debe corresponder a una actividad registrada para la empresa.</div>
                @error('economic_activity_code')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                @if ($siatActivities->isEmpty())
                    <div class="form-hint text-warning">Sincroniza el catalogo SIAT de actividades antes de homologar productos.</div>
                @endif
            </div>

            <div class="col-lg-4">
                <label class="form-label" for="product-siat-code">Codigo producto SIN</label>
                <select
                    class="form-select @error('siat_product_code') is-invalid @enderror"
                    id="product-siat-code"
                    name="siat_product_code"
                    data-tom-select
                    data-allow-empty-option="false"
                    data-product-siat-code
                    data-placeholder="Buscar producto SIAT"
                    aria-describedby="product-siat-code-help"
                    required
                >
                    <option value="" disabled>Selecciona una actividad primero</option>
                    @foreach ($siatProducts as $siatProduct)
                        <option
                            value="{{ $siatProduct['product_code'] }}"
                            data-activity-code="{{ $siatProduct['activity_code'] }}"
                            @selected((string) $selectedSiatProductCode === (string) $siatProduct['product_code'] && (string) $selectedEconomicActivity === (string) $siatProduct['activity_code'])
                        >
                            {{ $siatProduct['product_code'] }} - {{ $siatProduct['description'] }}{{ $siatProduct['is_active'] ? '' : ' (inactiva)' }}
                        </option>
                    @endforeach
                </select>
                <div class="form-hint" id="product-siat-code-help">Codigo generico homologado del catalogo sincronizado del SIN.</div>
                @error('siat_product_code')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                @if ($siatProducts->isEmpty())
                    <div class="form-hint text-warning">Sincroniza el catalogo SIAT de productos servicios para habilitar opciones.</div>
                @endif
            </div>

            <div class="col-lg-3">
                <label class="form-label" for="product-unit-price">Precio unitario predeterminado</label>
                <div class="input-group">
                    <span class="input-group-text">Bs</span>
                    <input
                        class="form-control @error('unit_price') is-invalid @enderror"
                        id="product-unit-price"
                        name="unit_price"
                        type="number"
                        min="0"
                        max="999999999999999.99999"
                        step="0.00001"
                        inputmode="decimal"
                        value="{{ old('unit_price', $product?->unit_price) }}"
                        required
                    >
                    @error('unit_price')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
    </section>

    <section class="product-form-section product-form-status" aria-labelledby="product-status-heading">
        <div>
            <h2 class="product-form-section-title" id="product-status-heading">Disponibilidad</h2>
        </div>
        <div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input
                class="form-check-input"
                id="product-active"
                name="is_active"
                type="checkbox"
                value="1"
                @checked(old('is_active', $product?->is_active ?? true))
            >
            <label class="form-check-label" for="product-active">Producto activo</label>
        </div>
    </section>

    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
        <a class="btn btn-outline-secondary" href="{{ route('parameters.products.index') }}">
            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Volver
        </a>
        <button class="btn btn-primary" type="submit">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Guardar producto
        </button>
    </div>
</x-ui.form-panel>
