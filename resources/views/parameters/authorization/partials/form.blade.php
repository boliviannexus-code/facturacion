@php
    $authorization ??= null;
    $selectedEnvironment = old(
        'environment_code',
        $authorization?->environment_code?->value ?? \App\Enums\SiatEnvironment::TestingAndPilot->value
    );
    $selectedModality = old(
        'modality_code',
        $authorization?->modality_code?->value ?? \App\Enums\SiatModality::ComputerizedOnline->value
    );
@endphp

<x-ui.form-panel :action="$action" :method="$method">
    <section class="authorization-form-section" aria-labelledby="authorization-taxpayer-heading">
        <div class="authorization-form-section-header">
            <span class="authorization-form-section-icon" aria-hidden="true"><i class="ti ti-building-bank"></i></span>
            <div>
                <h2 class="authorization-form-section-title" id="authorization-taxpayer-heading">Contribuyente</h2>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <label class="form-label" for="authorization-tax-id">NIT</label>
                <input
                    class="form-control @error('tax_id') is-invalid @enderror"
                    id="authorization-tax-id"
                    name="tax_id"
                    type="text"
                    inputmode="numeric"
                    maxlength="30"
                    value="{{ old('tax_id', $authorization?->tax_id) }}"
                    required
                    autofocus
                >
                @error('tax_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-8">
                <label class="form-label" for="authorization-legal-name">Razon social</label>
                <input
                    class="form-control @error('legal_name') is-invalid @enderror"
                    id="authorization-legal-name"
                    name="legal_name"
                    type="text"
                    maxlength="255"
                    value="{{ old('legal_name', $authorization?->legal_name) }}"
                    required
                >
                @error('legal_name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </section>

    <section class="authorization-form-section" aria-labelledby="authorization-system-heading">
        <div class="authorization-form-section-header">
            <span class="authorization-form-section-icon authorization-form-section-icon-sin" aria-hidden="true"><i class="ti ti-certificate"></i></span>
            <div>
                <h2 class="authorization-form-section-title" id="authorization-system-heading">Sistema autorizado</h2>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <label class="form-label" for="authorization-system-code">Codigo de sistema</label>
                <input
                    class="form-control @error('system_code') is-invalid @enderror"
                    id="authorization-system-code"
                    name="system_code"
                    type="password"
                    maxlength="255"
                    value=""
                    aria-describedby="authorization-system-code-help"
                    @required(! $authorization)
                >
                <div class="form-hint" id="authorization-system-code-help">
                    @if ($authorization)
                        Registrado: {{ $authorization->masked_system_code }}. Dejar vacio mantiene el valor actual.
                    @else
                        Parametro SIAT: codigoSistema.
                    @endif
                </div>
                @error('system_code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-2">
                <label class="form-label" for="authorization-environment-code">Ambiente</label>
                <select
                    class="form-select @error('environment_code') is-invalid @enderror"
                    id="authorization-environment-code"
                    name="environment_code"
                    required
                >
                    @foreach ($environments as $code => $label)
                        <option value="{{ $code }}" @selected((string) $selectedEnvironment === (string) $code)>
                            {{ $label }} ({{ $code }})
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">Parametro SIAT: codigoAmbiente.</div>
                @error('environment_code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-2">
                <label class="form-label" for="authorization-modality-code">Modalidad</label>
                <select
                    class="form-select @error('modality_code') is-invalid @enderror"
                    id="authorization-modality-code"
                    name="modality_code"
                    required
                >
                    @foreach ($modalities as $code => $label)
                        <option value="{{ $code }}" @selected((string) $selectedModality === (string) $code)>
                            {{ $label }} ({{ $code }})
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">Parametro SIAT: codigoModalidad.</div>
                @error('modality_code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-4">
                <label class="form-label" for="authorization-certificate-expiry">Vencimiento del certificado</label>
                <input
                    class="form-control @error('certificate_expires_at') is-invalid @enderror"
                    id="authorization-certificate-expiry"
                    name="certificate_expires_at"
                    type="datetime-local"
                    value="{{ old('certificate_expires_at', $authorization?->certificate_expires_at?->format('Y-m-d\\TH:i')) }}"
                    aria-describedby="authorization-certificate-expiry-help"
                >
                <div class="form-hint" id="authorization-certificate-expiry-help">Permite alertar antes del vencimiento; no almacena el certificado.</div>
                @error('certificate_expires_at')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </section>

    <section class="authorization-form-section" aria-labelledby="authorization-service-heading">
        <div class="authorization-form-section-header">
            <span class="authorization-form-section-icon authorization-form-section-icon-service" aria-hidden="true"><i class="ti ti-plug-connected"></i></span>
            <div>
                <h2 class="authorization-form-section-title" id="authorization-service-heading">Constantes de servicios</h2>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <label class="form-label" for="authorization-branch-code">Codigo de sucursal</label>
                <input
                    class="form-control @error('branch_code') is-invalid @enderror"
                    id="authorization-branch-code"
                    name="branch_code"
                    type="number"
                    min="0"
                    max="2147483647"
                    step="1"
                    inputmode="numeric"
                    value="{{ old('branch_code', $authorization?->branch_code ?? 0) }}"
                    aria-describedby="authorization-branch-code-help"
                    required
                >
                <div class="form-hint" id="authorization-branch-code-help">Parametro SIAT: codigoSucursal.</div>
                @error('branch_code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-6">
                <label class="form-label" for="authorization-pos-code">Codigo de punto de venta</label>
                <input
                    class="form-control @error('point_of_sale_code') is-invalid @enderror"
                    id="authorization-pos-code"
                    name="point_of_sale_code"
                    type="number"
                    min="0"
                    max="2147483647"
                    step="1"
                    inputmode="numeric"
                    value="{{ old('point_of_sale_code', $authorization?->point_of_sale_code) }}"
                    aria-describedby="authorization-pos-code-help"
                >
                <div class="form-hint" id="authorization-pos-code-help">Parametro SIAT: codigoPuntoVenta.</div>
                @error('point_of_sale_code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </section>

    <section class="authorization-form-section" aria-labelledby="authorization-emission-heading">
        <div class="authorization-form-section-header">
            <span class="authorization-form-section-icon authorization-form-section-icon-service" aria-hidden="true"><i class="ti ti-wifi-off"></i></span>
            <div>
                <h2 class="authorization-form-section-title" id="authorization-emission-heading">Modo de emisión</h2>
            </div>
        </div>

        <input type="hidden" name="force_offline_emission" value="0">
        <label class="form-check form-switch">
            <input
                class="form-check-input @error('force_offline_emission') is-invalid @enderror"
                id="authorization-force-offline-emission"
                name="force_offline_emission"
                type="checkbox"
                value="1"
                @checked((bool) old('force_offline_emission', $authorization?->force_offline_emission ?? false))
            >
            <span class="form-check-label">Forzar emisión fuera de línea</span>
        </label>
        <div class="form-hint mt-2">
            Mientras esté activo, las nuevas facturas se emitirán localmente con el CUFD vigente y quedarán pendientes de regularización en Contingencias. Desactívalo cuando se restablezca la operación en línea.
        </div>
        @error('force_offline_emission')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </section>

    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
        <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">
            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Volver
        </a>
        <button class="btn btn-primary" type="submit">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Guardar autorizacion
        </button>
    </div>
</x-ui.form-panel>
