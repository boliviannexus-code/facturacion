@extends('layouts.admin')

@section('title', 'Token API | '.config('app.name', 'Base Admin'))
@section('page-title', 'Token API')
@section('page-subtitle', 'Registro de token devuelto por la plataforma de impuestos')

@section('content')
    <div class="authorization-layout">
        @can('sin-api-tokens.manage')
            @include('sin-api-token.partials.form', [
                'action' => $apiToken
                    ? route('sin-api-token.update')
                    : route('sin-api-token.store'),
                'method' => $apiToken ? 'PUT' : 'POST',
                'apiToken' => $apiToken,
                'wsdlOptions' => $wsdlOptions,
            ])
        @endcan

        <section class="card authorization-summary" aria-labelledby="api-token-summary-heading">
            <div class="card-header">
                <h3 class="card-title mb-0" id="api-token-summary-heading">Registro actual</h3>
            </div>
            <div class="card-body">
                @if ($apiToken)
                    <dl class="authorization-kv">
                        <dt>Estado</dt>
                        <dd><span class="badge {{ $apiToken->status_badge }}">{{ $apiToken->status_label }}</span></dd>

                        <dt>Token API</dt>
                        <dd><span class="authorization-secret">{{ $apiToken->masked_api_token }}</span></dd>

                        <dt>WSDL</dt>
                        <dd>{{ $apiToken->wsdl_url }}</dd>

                        <dt>Inicio</dt>
                        <dd>{{ $apiToken->starts_at?->format('d/m/Y') ?? '-' }}</dd>

                        <dt>Fin</dt>
                        <dd>{{ $apiToken->ends_at?->format('d/m/Y') ?? '-' }}</dd>

                        <dt>Actualizado</dt>
                        <dd>{{ $apiToken->updated_at?->format('d/m/Y H:i') ?? '-' }}</dd>
                    </dl>
                @else
                    <div class="empty">
                        <div class="empty-icon">
                            <i class="ti ti-key"></i>
                        </div>
                        <p class="empty-title">Sin token API registrado</p>
                        <p class="empty-subtitle text-secondary">
                            Registra el token entregado por la plataforma de impuestos para habilitar integraciones.
                        </p>
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
