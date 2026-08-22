@php
    $customer ??= null;
    $selectedIdentityDocumentType = old('identity_document_type_code', $customer?->identity_document_type_code);
@endphp

<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label" for="customer-document-type">Tipo documento identidad</label>
        <select
            class="form-select @error('identity_document_type_code') is-invalid @enderror"
            id="customer-document-type"
            name="identity_document_type_code"
            data-tom-select
            data-allow-empty-option="false"
            data-placeholder="Buscar tipo documento"
            required
            autofocus
        >
            <option value="" disabled>Seleccionar tipo</option>
            @foreach ($identityDocumentTypes as $documentType)
                <option value="{{ $documentType['code'] }}" @selected((string) $selectedIdentityDocumentType === (string) $documentType['code'])>
                    {{ $documentType['code'] }} - {{ $documentType['description'] }}{{ $documentType['is_active'] ? '' : ' (inactivo)' }}
                </option>
            @endforeach
        </select>
        @error('identity_document_type_code')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
        <div class="invalid-feedback d-block" data-error-for="identity_document_type_code"></div>
        @if ($identityDocumentTypes->isEmpty())
            <div class="form-hint text-warning">Sincroniza el catalogo SIAT de tipos documento identidad antes de registrar clientes.</div>
        @endif
    </div>

    <div class="col-md-5">
        <label class="form-label" for="customer-document-number">Numero documento</label>
        <input
            class="form-control @error('document_number') is-invalid @enderror"
            id="customer-document-number"
            name="document_number"
            type="text"
            maxlength="80"
            value="{{ old('document_number', $customer?->document_number) }}"
            required
        >
        @error('document_number')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="invalid-feedback" data-error-for="document_number"></div>
    </div>

    <div class="col-md-3">
        <label class="form-label" for="customer-document-complement">Complemento</label>
        <input
            class="form-control @error('document_complement') is-invalid @enderror"
            id="customer-document-complement"
            name="document_complement"
            type="text"
            maxlength="20"
            value="{{ old('document_complement', $customer?->document_complement) }}"
        >
        @error('document_complement')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="invalid-feedback" data-error-for="document_complement"></div>
    </div>

    <div class="col-md-4">
        <label class="form-label" for="customer-code">Codigo cliente</label>
        <input
            class="form-control"
            id="customer-code"
            type="text"
            value="{{ $customer?->customer_code ?? 'Se generara automaticamente' }}"
            readonly
        >
    </div>

    <div class="col-md-6">
        <label class="form-label" for="customer-name">Nombre/Razon social</label>
        <input
            class="form-control @error('name') is-invalid @enderror"
            id="customer-name"
            name="name"
            type="text"
            maxlength="255"
            value="{{ old('name', $customer?->name) }}"
            required
        >
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="invalid-feedback" data-error-for="name"></div>
    </div>

    <div class="col-md-2 d-flex align-items-end">
        <div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input
                class="form-check-input"
                id="customer-active"
                name="is_active"
                type="checkbox"
                value="1"
                @checked(old('is_active', $customer?->is_active ?? true))
            >
            <label class="form-check-label" for="customer-active">Activo</label>
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label" for="customer-email">Correo</label>
        <input
            class="form-control @error('email') is-invalid @enderror"
            id="customer-email"
            name="email"
            type="email"
            maxlength="255"
            value="{{ old('email', $customer?->email) }}"
        >
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="invalid-feedback" data-error-for="email"></div>
    </div>

    <div class="col-md-6">
        <label class="form-label" for="customer-phone">Telefono</label>
        <input
            class="form-control @error('phone') is-invalid @enderror"
            id="customer-phone"
            name="phone"
            type="text"
            maxlength="80"
            value="{{ old('phone', $customer?->phone) }}"
        >
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="invalid-feedback" data-error-for="phone"></div>
    </div>

    <div class="col-12">
        <label class="form-label" for="customer-address">Direccion</label>
        <input
            class="form-control @error('address') is-invalid @enderror"
            id="customer-address"
            name="address"
            type="text"
            maxlength="255"
            value="{{ old('address', $customer?->address) }}"
        >
        @error('address')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="invalid-feedback" data-error-for="address"></div>
    </div>
</div>
