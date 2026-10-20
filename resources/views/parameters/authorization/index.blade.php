@extends('layouts.admin')

@section('title', 'Autorizacion SIN | '.config('app.name', 'Base Admin'))
@section('page-title', 'Autorizacion SIN')
@section('page-subtitle', 'Parametros de integracion por empresa')

@section('content')
    <div class="authorization-layout">
        @can('sin-authorizations.manage')
            @include('parameters.authorization.partials.form', [
                'action' => $authorization
                    ? route('parameters.authorization.update')
                    : route('parameters.authorization.store'),
                'method' => $authorization ? 'PUT' : 'POST',
                'authorization' => $authorization,
            ])
        @endcan

        <section class="card authorization-summary" aria-labelledby="authorization-summary-heading">
            <div class="card-header">
                <h3 class="card-title mb-0" id="authorization-summary-heading">Registro actual</h3>
            </div>
            <div class="card-body">
                @if ($authorization)
                    <dl class="authorization-kv">
                        <dt>NIT</dt>
                        <dd>{{ $authorization->tax_id }}</dd>

                        <dt>Razon social</dt>
                        <dd>{{ $authorization->legal_name }}</dd>

                        <dt>Ambiente</dt>
                        <dd>{{ $authorization->environment_code->label() }} ({{ $authorization->environment_code->value }})</dd>

                        <dt>Modalidad</dt>
                        <dd>{{ $authorization->modality_code->label() }} ({{ $authorization->modality_code->value }})</dd>

                        <dt>Sucursal</dt>
                        <dd>{{ $authorization->branch_code }}</dd>

                        <dt>Punto de venta</dt>
                        <dd>{{ $authorization->point_of_sale_code ?? '-' }}</dd>

                        <dt>Codigo sistema</dt>
                        <dd><span class="authorization-secret">{{ $authorization->masked_system_code }}</span></dd>

                        <dt>Vencimiento certificado</dt>
                        <dd>{{ $authorization->certificate_expires_at?->format('d/m/Y H:i') ?? 'No registrado' }}</dd>

                        <dt>Modo de emisión</dt>
                        <dd>
                            @if ($authorization->force_offline_emission)
                                <span class="badge bg-warning-lt text-warning"><i class="ti ti-wifi-off me-1"></i>Fuera de línea forzado</span>
                            @else
                                <span class="badge bg-success-lt text-success"><i class="ti ti-wifi me-1"></i>Automático</span>
                            @endif
                        </dd>

                        <dt>Actualizado</dt>
                        <dd>{{ $authorization->updated_at?->format('d/m/Y H:i') ?? '-' }}</dd>
                    </dl>
                @else
                    <div class="empty">
                        <div class="empty-icon">
                            <i class="ti ti-certificate"></i>
                        </div>
                        <p class="empty-title">Sin autorizacion registrada</p>
                        <p class="empty-subtitle text-secondary">
                            La empresa aun no tiene parametros base para consumir servicios SIAT.
                        </p>
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
