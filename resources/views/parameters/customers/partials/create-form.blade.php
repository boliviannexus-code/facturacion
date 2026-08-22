<form method="POST" action="{{ route('parameters.customers.store') }}" data-ajax-form data-invoice-customer-create="1" novalidate>
    @csrf
    @include('parameters.customers.partials.fields', [
        'customer' => null,
        'identityDocumentTypes' => $identityDocumentTypes,
    ])

    <div class="d-flex justify-content-end gap-2 mt-4">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">
            <span class="spinner-border spinner-border-sm me-2 d-none" data-submit-spinner></span>
            Crear cliente
        </button>
    </div>
</form>
