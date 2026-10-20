@php
    $apiToken ??= null;
@endphp

<x-ui.form-panel :action="$action" :method="$method">
    <section class="authorization-form-section" aria-labelledby="api-token-register-heading">
        <div class="authorization-form-section-header">
            <span class="authorization-form-section-icon authorization-form-section-icon-sin" aria-hidden="true"><i class="ti ti-key"></i></span>
            <div>
                <h2 class="authorization-form-section-title" id="api-token-register-heading">Token recibido</h2>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="api-token-value">Token API</label>
                <textarea
                    class="form-control @error('api_token') is-invalid @enderror"
                    id="api-token-value"
                    name="api_token"
                    rows="5"
                    maxlength="4096"
                    aria-describedby="api-token-help"
                    @required(! $apiToken)
                ></textarea>
                <div class="form-hint" id="api-token-help">
                    @if ($apiToken)
                        Registrado: {{ $apiToken->masked_api_token }}. Dejar vacio mantiene el valor actual.
                    @else
                        Pega el token API devuelto por la plataforma de impuestos.
                    @endif
                </div>
                @error('api_token')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </section>

    <p class="text-body-secondary">Los servicios se configuran por empresa y ambiente en <a href="{{ route('siat.wsdl-services.index') }}">Servicios WSDL</a>.</p>

    <section class="authorization-form-section" aria-labelledby="api-token-validity-heading">
        <div class="authorization-form-section-header">
            <span class="authorization-form-section-icon authorization-form-section-icon-service" aria-hidden="true"><i class="ti ti-calendar-time"></i></span>
            <div>
                <h2 class="authorization-form-section-title" id="api-token-validity-heading">Vigencia</h2>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <label class="form-label" for="api-token-starts-at">Fecha inicio</label>
                <input
                    class="form-control @error('starts_at') is-invalid @enderror"
                    id="api-token-starts-at"
                    name="starts_at"
                    type="date"
                    value="{{ old('starts_at', $apiToken?->starts_at?->toDateString()) }}"
                    required
                >
                @error('starts_at')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-6">
                <label class="form-label" for="api-token-ends-at">Fecha fin</label>
                <input
                    class="form-control @error('ends_at') is-invalid @enderror"
                    id="api-token-ends-at"
                    name="ends_at"
                    type="date"
                    value="{{ old('ends_at', $apiToken?->ends_at?->toDateString()) }}"
                    required
                >
                @error('ends_at')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </section>

    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
        <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">
            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Volver
        </a>
        <button class="btn btn-primary" type="submit">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Guardar token
        </button>
    </div>
</x-ui.form-panel>
