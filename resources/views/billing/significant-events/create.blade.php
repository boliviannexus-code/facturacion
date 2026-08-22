@extends('layouts.admin')

@section('title', 'Evento significativo | '.config('app.name', 'Base Admin'))
@section('page-title', 'Registrar evento significativo')
@section('page-subtitle', $invoice ? 'Contingencia del intento de factura Nro. '.$invoice->attempted_invoice_number : 'Registro independiente mediante el servicio de Operaciones del SIAT')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <div class="alert alert-warning border-start border-4" role="alert">
                <h2 class="h5 mb-1">{{ $invoice ? 'Factura no enviada por falta de conexión con el SIN' : 'Comunicar una contingencia sin vincularla a una factura' }}</h2>
                <p class="mb-0">
                    @if($pointOfSale)
                        Sucursal {{ $pointOfSale->branch->branch_code }}, punto de venta {{ $pointOfSale->point_of_sale_code }}.
                    @endif
                    Registra el período real de la contingencia. Esta operación se comunica directamente con el servicio de Operaciones del SIAT.
                </p>
            </div>

            @if (! $invoice && $pointsOfSale->isEmpty())
                <div class="alert alert-danger" role="alert">
                    No existen puntos de venta activos. Registra o habilita un punto de venta antes de comunicar el evento.
                </div>
            @endif

            @if (! $invoice && $pendingOfflineEvent)
                <div class="alert alert-danger" role="alert">
                    <h2 class="h5 mb-1">Ya existe una contingencia con facturas pendientes</h2>
                    <p class="mb-2">El evento #{{ $pendingOfflineEvent->id }} contiene facturas emitidas fuera de línea. Debes registrar ese mismo evento antes de generar paquetes; no corresponde crear otro evento independiente.</p>
                    @can('contingencies.view')
                        <a class="btn btn-danger btn-sm" href="{{ route('billing.contingencies.index', ['point_of_sale_id' => $pointOfSale->id]) }}">Ir a Contingencias</a>
                    @endcan
                </div>
            @endif

            @if ($events->isEmpty())
                <div class="alert alert-danger" role="alert">
                    No existen eventos significativos sincronizados. Sincroniza primero el catálogo
                    <strong>Eventos significativos</strong> desde Paramétricas SIAT.
                </div>
            @endif

            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Datos del evento</h3></div>
                <form method="POST" action="{{ $invoice ? route('billing.significant-events.store', $invoice) : route('billing.significant-events.point-of-sale.store') }}">
                    @csrf
                    <div class="card-body">
                        @unless($invoice)
                            <div class="mb-3">
                                <label class="form-label required" for="event-point-of-sale">Sucursal y punto de venta</label>
                                <select class="form-select @error('sin_point_of_sale_id') is-invalid @enderror" id="event-point-of-sale" name="sin_point_of_sale_id" required @disabled($pointsOfSale->isEmpty())>
                                    <option value="">Seleccionar punto de venta</option>
                                    @foreach($pointsOfSale as $availablePoint)
                                        <option value="{{ $availablePoint->id }}" @selected((string) old('sin_point_of_sale_id', $pointOfSale?->id) === (string) $availablePoint->id)>
                                            Sucursal {{ $availablePoint->branch->branch_code }} · PV {{ $availablePoint->point_of_sale_code }} · {{ $availablePoint->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('sin_point_of_sale_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-hint">El evento quedará asociado a este contexto fiscal, no a una factura específica.</div>
                            </div>
                        @endunless

                        <div class="mb-3">
                            <label class="form-label" for="event-code">Evento significativo</label>
                            <select class="form-select @error('event_code') is-invalid @enderror" id="event-code" name="event_code" required @disabled($events->isEmpty())>
                                <option value="">Seleccionar evento</option>
                                @foreach ($events as $event)
                                    <option value="{{ $event->classifier_code }}" @selected(old('event_code') == $event->classifier_code)>
                                        {{ $event->classifier_code }} - {{ $event->description }}
                                    </option>
                                @endforeach
                            </select>
                            @error('event_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="event-description">Descripción de la contingencia</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" id="event-description" name="description" maxlength="500" rows="3" required>{{ old('description', $invoice ? 'Falla de comunicación con los servicios del SIN durante la emisión de factura.' : 'Interrupción de la comunicación con los servicios del SIN en el punto de venta.') }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="event-started-at">Inicio del evento</label>
                                <input class="form-control @error('started_at') is-invalid @enderror" id="event-started-at" name="started_at" type="datetime-local" value="{{ old('started_at', $invoice?->issued_at?->format('Y-m-d\TH:i') ?? now()->subMinutes(5)->format('Y-m-d\TH:i')) }}" required>
                                @error('started_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="event-ended-at">Fin del evento</label>
                                <input class="form-control @error('ended_at') is-invalid @enderror" id="event-ended-at" name="ended_at" type="datetime-local" value="{{ old('ended_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}" required>
                                @error('ended_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-hint">Debe ser posterior al inicio; registra el intervalo real de la contingencia.</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <a class="btn btn-outline-secondary" href="{{ $invoice ? route('billing.invoices.issue.show', 1) : (auth()->user()->can('contingencies.view') ? route('billing.contingencies.index') : route('dashboard')) }}">{{ $invoice ? 'Volver a facturación' : 'Volver' }}</a>
                        <button class="btn btn-primary" type="submit" @disabled($events->isEmpty() || ! $pointOfSale || $pendingOfflineEvent || ($invoice && $registeredEvents->contains('transaccion', true)))>
                            <i class="ti ti-cloud-upload me-1" aria-hidden="true"></i>Registrar en el SIN
                        </button>
                    </div>
                </form>
            </div>

            @if ($registeredEvents->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Historial del punto de venta</h3></div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead><tr><th>Evento</th><th>Período</th><th>Estado</th><th>Código recepción</th><th>Mensaje</th></tr></thead>
                            <tbody>
                                @foreach ($registeredEvents as $registeredEvent)
                                    <tr>
                                        <td>{{ $registeredEvent->event_code }} - {{ $registeredEvent->event_description }}</td>
                                        <td>{{ $registeredEvent->started_at?->format('d/m/Y H:i') }}<br>{{ $registeredEvent->ended_at?->format('d/m/Y H:i') }}</td>
                                        <td>{{ $registeredEvent->status_label }}</td>
                                        <td>{{ $registeredEvent->reception_code ?: '-' }}</td>
                                        <td>{{ $registeredEvent->message }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const startedAt = document.getElementById('event-started-at');
            const endedAt = document.getElementById('event-ended-at');

            if (!startedAt || !endedAt) {
                return;
            }

            const syncRange = () => {
                endedAt.min = startedAt.value;

                if (startedAt.value && endedAt.value && endedAt.value <= startedAt.value) {
                    const nextMinute = new Date(`${startedAt.value}:00`);
                    nextMinute.setMinutes(nextMinute.getMinutes() + 1);
                    endedAt.value = `${nextMinute.getFullYear()}-${String(nextMinute.getMonth() + 1).padStart(2, '0')}-${String(nextMinute.getDate()).padStart(2, '0')}T${String(nextMinute.getHours()).padStart(2, '0')}:${String(nextMinute.getMinutes()).padStart(2, '0')}`;
                }
            };

            startedAt.addEventListener('change', syncRange);
            endedAt.addEventListener('change', syncRange);
            syncRange();
        });
    </script>
@endsection
