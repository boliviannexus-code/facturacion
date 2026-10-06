@extends('layouts.admin')

@section('title', 'Verificar comunicacion SIAT | '.config('app.name', 'Base Admin'))
@section('page-title', 'Verificar comunicacion')
@section('page-subtitle', 'Prueba de conexion al servicio SIAT configurado')

@section('content')
    <div class="row g-3">
        <div class="col-lg-5">
            <section class="card" aria-labelledby="siat-configuration-heading">
                <div class="card-header">
                    <h3 class="card-title mb-0" id="siat-configuration-heading">Configuracion usada</h3>
                </div>
                <div class="card-body">
                    @if ($apiToken)
                        <dl class="authorization-kv">
                            <dt>Estado token</dt>
                            <dd><span class="badge {{ $apiToken->status_badge }}">{{ $apiToken->status_label }}</span></dd>

                            <dt>Token API</dt>
                            <dd><span class="authorization-secret">{{ $apiToken->masked_api_token }}</span></dd>

                            <dt>WSDL</dt>
                            <dd>{{ $apiToken->wsdl_url }}</dd>

                            <dt>Vigencia</dt>
                            <dd>{{ $apiToken->starts_at?->format('d/m/Y') }} - {{ $apiToken->ends_at?->format('d/m/Y') }}</dd>
                        </dl>
                    @else
                        <div class="empty">
                            <div class="empty-icon">
                                <i class="ti ti-key"></i>
                            </div>
                            <p class="empty-title">Falta configurar Token API</p>
                            <p class="empty-subtitle text-secondary">
                                Registra el token API y la URL WSDL antes de probar la comunicacion.
                            </p>
                        </div>
                    @endif
                </div>
                <div class="card-footer d-flex flex-column flex-sm-row gap-2 justify-content-end">
                    @can('sin-api-tokens.view')
                        <a class="btn btn-outline-secondary" href="{{ route('sin-api-token.index') }}">
                            <i class="ti ti-settings me-1" aria-hidden="true"></i>Token API
                        </a>
                    @endcan
                    @can('siat-communication.verify')
                        <form method="POST" action="{{ route('siat.communication.verify') }}">
                            @csrf
                            <button class="btn btn-primary" type="submit" @disabled(! $apiToken)>
                                <i class="ti ti-plug-connected me-1" aria-hidden="true"></i>Verificar comunicacion
                            </button>
                        </form>
                    @endcan
                </div>
            </section>
        </div>

        <div class="col-lg-7">
            <section class="card" aria-labelledby="siat-result-heading">
                <div class="card-header">
                    <h3 class="card-title mb-0" id="siat-result-heading">Resultado</h3>
                </div>
                <div class="card-body">
                    @if ($result)
                        <div class="alert {{ $result['ok'] ? 'alert-success' : 'alert-danger' }}" role="alert">
                            <div class="d-flex">
                                <div>
                                    <i class="ti {{ $result['ok'] ? 'ti-circle-check' : 'ti-alert-triangle' }} alert-icon"></i>
                                </div>
                                <div>
                                    <h4 class="alert-title">{{ $result['ok'] ? 'Comunicacion exitosa' : 'Comunicacion fallida' }}</h4>
                                    <div class="text-secondary">{{ $result['message'] }}</div>
                                </div>
                            </div>
                        </div>

                        <dl class="authorization-kv">
                            <dt>Operacion</dt>
                            <dd>{{ $result['operation'] }}</dd>

                            <dt>WSDL</dt>
                            <dd>{{ $result['wsdl_url'] }}</dd>

                            <dt>Duracion</dt>
                            <dd>{{ $result['duration_ms'] }} ms</dd>

                            <dt>Verificado</dt>
                            <dd>{{ $result['checked_at'] }}</dd>
                        </dl>

                        @if (! empty($result['response']))
                            <pre class="mt-3 mb-0 p-3 bg-muted-lt rounded text-secondary small">{{ json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        @endif
                    @else
                        <div class="empty">
                            <div class="empty-icon">
                                <i class="ti ti-plug-connected"></i>
                            </div>
                            <p class="empty-title">Sin verificacion reciente</p>
                            <p class="empty-subtitle text-secondary">
                                Ejecuta la prueba para consultar la operacion verificarComunicacion.
                            </p>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
