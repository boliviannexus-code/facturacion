<x-ui.form-panel :action="$action" :method="$method">
    @include('parameters.customers.partials.fields', [
        'customer' => $customer ?? null,
        'identityDocumentTypes' => $identityDocumentTypes,
    ])

    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
        <a class="btn btn-outline-secondary" href="{{ route('parameters.customers.index') }}">
            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Volver
        </a>
        <button class="btn btn-primary" type="submit">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Guardar
        </button>
    </div>
</x-ui.form-panel>
