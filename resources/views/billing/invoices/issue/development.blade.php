@extends('layouts.admin')

@section('title', 'Formulario en desarrollo | '.config('app.name', 'Base Admin'))
@section('page-title', $sector->description)
@section('page-subtitle', 'Codigo documento sector '.$sector->classifier_code)

@section('content')
    <div class="row g-3">
        <div class="col-lg-8">
            <x-ui.card title="Formulario en desarrollo">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3">
                        <span class="product-form-section-icon product-form-section-icon-siat" aria-hidden="true"><i class="ti ti-tools"></i></span>
                        <div>
                            <p class="mb-3">El formulario para {{ $sector->description }} todavia esta en desarrollo.</p>
                            <a class="btn btn-outline-secondary" href="{{ route('billing.invoices.issue.index') }}">
                                <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Volver
                            </a>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div class="col-lg-4">
            <x-ui.card title="Sectores activos">
                <div class="list-group list-group-flush">
                    @foreach ($documentSectors as $documentSector)
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ (string) $documentSector->classifier_code === (string) $sector->classifier_code ? 'active' : '' }}" href="{{ route('billing.invoices.issue.show', $documentSector->classifier_code) }}">
                            <span>{{ $documentSector->description }}</span>
                            <span class="badge bg-secondary-lt">{{ $documentSector->classifier_code }}</span>
                        </a>
                    @endforeach
                </div>
            </x-ui.card>
        </div>
    </div>
@endsection
