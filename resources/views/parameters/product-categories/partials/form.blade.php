@php
    $category ??= null;
@endphp

<x-ui.form-panel :action="$action" :method="$method">
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label" for="category-name">Nombre</label>
            <input
                class="form-control @error('name') is-invalid @enderror"
                id="category-name"
                name="name"
                type="text"
                maxlength="255"
                value="{{ old('name', $category?->name) }}"
                required
                autofocus
            >
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input
                    class="form-check-input"
                    id="category-active"
                    name="is_active"
                    type="checkbox"
                    value="1"
                    @checked(old('is_active', $category?->is_active ?? true))
                >
                <label class="form-check-label" for="category-active">Activa</label>
            </div>
        </div>

        <div class="col-12">
            <label class="form-label" for="category-description">Descripcion</label>
            <textarea
                class="form-control @error('description') is-invalid @enderror"
                id="category-description"
                name="description"
                rows="4"
                maxlength="1000"
            >{{ old('description', $category?->description) }}</textarea>
            @error('description')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
        <a class="btn btn-outline-secondary" href="{{ route('parameters.categories.index') }}">
            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Volver
        </a>
        <button class="btn btn-primary" type="submit">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Guardar
        </button>
    </div>
</x-ui.form-panel>
