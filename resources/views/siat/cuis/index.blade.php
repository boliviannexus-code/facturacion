@extends('layouts.admin')

@section('title', 'CUIS | '.config('app.name', 'Base Admin'))
@section('page-title', 'CUIS')
@section('page-subtitle', 'Solicitud e historico del Codigo Unico de Inicio de Sistemas')

@section('content')
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <section class="card h-100" aria-labelledby="cuis-current-heading">
                <div class="card-header">
                    <h3 class="card-title mb-0" id="cuis-current-heading">CUIS actual</h3>
                </div>
                <div class="card-body">
                    @if ($currentCuis)
                        <dl class="authorization-kv">
                            <dt>Codigo</dt>
                            <dd><span class="authorization-secret">{{ $currentCuis->cuis_code }}</span></dd>

                            <dt>Generado</dt>
                            <dd>{{ $currentCuis->requested_at?->format('d/m/Y H:i') }}</dd>

                            <dt>Ambiente</dt>
                            <dd>{{ $currentCuis->environment_code->label() }} ({{ $currentCuis->environment_code->value }})</dd>

                            <dt>Sucursal</dt>
                            <dd>{{ $currentCuis->branch_code }}</dd>

                            <dt>Punto venta</dt>
                            <dd>{{ $currentCuis->point_of_sale_code }}</dd>
                        </dl>
                    @else
                        <div class="empty">
                            <div class="empty-icon">
                                <i class="ti ti-id-badge-2"></i>
                            </div>
                            <p class="empty-title">Sin CUIS generado</p>
                            <p class="empty-subtitle text-secondary">
                                Solicita el CUIS para que otros procedimientos SIAT puedan usarlo.
                            </p>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="card h-100" aria-labelledby="cuis-config-heading">
                <div class="card-header">
                    <h3 class="card-title mb-0" id="cuis-config-heading">Configuracion usada</h3>
                </div>
                <div class="card-body">
                    @if ($apiToken && $authorization)
                        <dl class="authorization-kv">
                            <dt>Token API</dt>
                            <dd><span class="authorization-secret">{{ $apiToken->masked_api_token }}</span></dd>

                            <dt>WSDL</dt>
                            <dd>{{ $apiToken->wsdl_url }}</dd>

                            <dt>NIT</dt>
                            <dd>{{ $authorization->tax_id }}</dd>

                            <dt>Codigo sistema</dt>
                            <dd><span class="authorization-secret">{{ $authorization->masked_system_code }}</span></dd>

                            <dt>Puntos activos</dt>
                            <dd>{{ $pointOptions->count() }}</dd>
                        </dl>
                    @else
                        <div class="empty">
                            <div class="empty-icon">
                                <i class="ti ti-alert-circle"></i>
                            </div>
                            <p class="empty-title">Configuracion incompleta</p>
                            <p class="empty-subtitle text-secondary">
                                Registra Token API, Autorizacion SIN y sucursales antes de solicitar CUIS.
                            </p>
                        </div>
                    @endif
                </div>
                <div class="card-footer d-flex flex-column flex-sm-row gap-2 justify-content-end">
                    @can('sin-api-tokens.view')
                        <a class="btn btn-outline-secondary" href="{{ route('sin-api-token.index') }}">
                            <i class="ti ti-key me-1" aria-hidden="true"></i>Token API
                        </a>
                    @endcan
                    @can('sin-authorizations.view')
                        <a class="btn btn-outline-secondary" href="{{ route('parameters.authorization.index') }}">
                            <i class="ti ti-certificate me-1" aria-hidden="true"></i>Autorizacion
                        </a>
                    @endcan
                    @can('siat-branches.view')
                        <a class="btn btn-outline-secondary" href="{{ route('siat.branches.index') }}">
                            <i class="ti ti-building-store me-1" aria-hidden="true"></i>Sucursales
                        </a>
                    @endcan
                </div>
                @can('siat-cuis.request')
                    <div class="card-footer">
                        <form method="POST" action="{{ route('siat.cuis.request') }}">
                            @csrf
                            <div class="row g-2 align-items-end">
                                <div class="col-lg-8">
                                    <label class="form-label" for="cuis-point-of-sale">Sucursal / punto de venta</label>
                                    <select
                                        class="form-select @error('sin_point_of_sale_id') is-invalid @enderror"
                                        id="cuis-point-of-sale"
                                        name="sin_point_of_sale_id"
                                        required
                                    >
                                        <option value="">Seleccionar</option>
                                        @foreach ($pointOptions as $point)
                                            <option value="{{ $point->id }}" @selected((string) old('sin_point_of_sale_id') === (string) $point->id)>
                                                Sucursal {{ $point->branch->branch_code }} - {{ $point->branch->name }} / PV {{ $point->point_of_sale_code }} - {{ $point->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('sin_point_of_sale_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-lg-4">
                                    <button class="btn btn-primary w-100" type="submit" @disabled(! $apiToken || ! $authorization || $pointOptions->isEmpty())>
                                        <i class="ti ti-send me-1" aria-hidden="true"></i>Solicitar CUIS
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer">
                        <details @if($errors->has('cuis_code')) open @endif>
                            <summary class="fw-semibold">Registrar CUIS existente</summary>
                            <p class="text-secondary small mt-2 mb-3">
                                Úsalo al migrar a una base nueva cuando el SIN indique que el CUIS ya fue generado pero no devuelva el código.
                            </p>
                            <form method="POST" action="{{ route('siat.cuis.import') }}">
                                @csrf
                                <div class="row g-2 align-items-end">
                                    <div class="col-lg-5">
                                        <label class="form-label" for="existing-cuis-point-of-sale">Sucursal / punto de venta</label>
                                        <select class="form-select @error('sin_point_of_sale_id') is-invalid @enderror" id="existing-cuis-point-of-sale" name="sin_point_of_sale_id" required>
                                            <option value="">Seleccionar</option>
                                            @foreach ($pointOptions as $point)
                                                <option value="{{ $point->id }}" @selected((string) old('sin_point_of_sale_id') === (string) $point->id)>
                                                    Suc. {{ $point->branch->branch_code }} / PV {{ $point->point_of_sale_code }} - {{ $point->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-lg-5">
                                        <label class="form-label" for="existing-cuis-code">CUIS vigente</label>
                                        <input class="form-control @error('cuis_code') is-invalid @enderror" id="existing-cuis-code" name="cuis_code" value="{{ old('cuis_code') }}" maxlength="128" autocomplete="off" required>
                                        @error('cuis_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-lg-2">
                                        <button class="btn btn-outline-primary w-100" type="submit" @disabled(! $apiToken || ! $authorization || $pointOptions->isEmpty())>
                                            <i class="ti ti-database-import me-1" aria-hidden="true"></i>Registrar
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </details>
                    </div>
                @else
                    <div class="card-footer d-flex flex-column flex-sm-row gap-2 justify-content-end">
                        @can('sin-api-tokens.view')
                            <a class="btn btn-outline-secondary" href="{{ route('sin-api-token.index') }}">
                                <i class="ti ti-key me-1" aria-hidden="true"></i>Token API
                            </a>
                        @endcan
                        @can('sin-authorizations.view')
                            <a class="btn btn-outline-secondary" href="{{ route('parameters.authorization.index') }}">
                                <i class="ti ti-certificate me-1" aria-hidden="true"></i>Autorizacion
                            </a>
                        @endcan
                    </div>
                @endcan
            </section>
        </div>
    </div>

    @if ($latestAttempt && ! $latestAttempt->transaccion)
        <div class="alert alert-warning" role="alert">
            <div class="d-flex">
                <div><i class="ti ti-alert-triangle alert-icon"></i></div>
                <div>
                    <h4 class="alert-title">Ultima solicitud observada</h4>
                    <div class="text-secondary">{{ $latestAttempt->message }}</div>
                </div>
            </div>
        </div>
    @endif

    <x-ui.table-card title="Historico CUIS">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>CUIS</th>
                    <th>Parametros</th>
                    <th>Duracion</th>
                    <th>Mensaje</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($history as $attempt)
                    <tr>
                        <td>{{ $attempt->requested_at?->format('d/m/Y H:i') }}</td>
                        <td><span class="badge {{ $attempt->status_badge }}">{{ $attempt->status_label }}</span></td>
                        <td>
                            @if ($attempt->cuis_code)
                                <span class="authorization-secret">{{ $attempt->cuis_code }}</span>
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            <div>NIT {{ $attempt->tax_id }}</div>
                            <div class="text-body-secondary small">
                                Amb. {{ $attempt->environment_code->value }},
                                Mod. {{ $attempt->modality_code->value }},
                                Suc. {{ $attempt->branch_code }},
                                PV {{ $attempt->point_of_sale_code }}
                            </div>
                        </td>
                        <td>{{ $attempt->duration_ms }} ms</td>
                        <td class="text-secondary">{{ $attempt->message ?? '-' }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row colspan="6" message="No hay solicitudes CUIS registradas." />
                @endforelse
            </tbody>
        </table>

        <x-slot:footer>{{ $history->links() }}</x-slot:footer>
    </x-ui.table-card>
@endsection
