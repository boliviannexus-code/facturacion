<div class="card mb-3 border-primary">
    <div class="card-header"><div><h3 class="card-title">CUFD y horarios de la contingencia</h3><div class="text-secondary small">Punto de venta: {{ $range->pointOfSale?->display_name ?? 'Sin asignar' }} · Zona horaria: {{ config('app.timezone') }}</div></div></div>
    <div class="card-body">
        @if($range->significantEvent)
            @php
                $eventCufd = $range->significantEvent->cufd;
            @endphp
            <div class="form-label">CUFD del evento y de las facturas</div>
            <code class="d-block text-break mb-3">{{ $eventCufd?->cufd_code ?? 'No disponible' }}</code>
            <div class="row g-3">
                <div class="col-md-6"><div class="text-secondary small">Solicitado</div><strong>{{ $eventCufd?->requested_at?->format('d/m/Y H:i:s') ?? 'No disponible' }}</strong></div>
                <div class="col-md-6"><div class="text-secondary small">Vencimiento</div><strong>{{ $eventCufd?->expires_at?->format('d/m/Y H:i:s') ?? 'No disponible' }}</strong></div>
                <div class="col-12"><div class="form-label">Horario permitido para las facturas (incluye ambos extremos)</div>{{ $range->significantEvent->started_at?->format('d/m/Y H:i:s') }} → {{ $range->significantEvent->ended_at?->format('d/m/Y H:i:s') }}</div>
                <div class="col-12"><div class="form-label">Regularizar hasta</div>{{ $range->significantEvent->ended_at?->addHours(72)->format('d/m/Y H:i:s') }} @if($range->significantEvent->ended_at?->addHours(72)->isPast())<span class="badge bg-danger-lt">Plazo vencido</span>@endif</div>
            </div>
            @if($range->significantEvent->recoveryCufd)
                <hr><div class="form-label">CUFD usado para registrar el evento ante el SIN</div><code class="d-block text-break">{{ $range->significantEvent->recoveryCufd->cufd_code }}</code>
                <div class="small text-secondary mt-2">Solicitado: {{ $range->significantEvent->recoveryCufd->requested_at?->format('d/m/Y H:i:s') }} · Vencimiento: {{ $range->significantEvent->recoveryCufd->expires_at?->format('d/m/Y H:i:s') }}</div>
            @endif
        @else
            @php
                $latestCufd = $eventCufds->first(fn ($cufd) => $cufd->requested_at->lte($eventClock) && $cufd->expires_at->gt($eventClock) && (! $cufd->invalidated_at || $cufd->invalidated_at->gt($eventClock)));
            @endphp
            <div data-c2-preview data-now="{{ $eventClock->format('Y-m-d\TH:i:s') }}" aria-live="polite" class="mb-3">
                <div class="form-label">Último CUFD disponible</div>
                @if($latestCufd)
                    <code class="d-block text-break mb-2">{{ $latestCufd->cufd_code }}</code>
                    <div class="small">Solicitado: {{ $latestCufd->requested_at->format('d/m/Y H:i:s') }} · Vencimiento: {{ $latestCufd->expires_at->format('d/m/Y H:i:s') }}</div>
                @else
                    <div class="text-warning">No hay un CUFD vigente en este momento.</div>
                @endif
                <div class="small text-secondary mt-2">Ingrese el inicio real del evento. El sistema mostrará el CUFD que corresponde a esa fecha y hora.</div>
            </div>
            <div class="form-label">Fin del evento permitido</div>
            <p>Desde <strong>{{ $eventClock->copy()->subHours(48)->format('d/m/Y H:i:s') }}</strong> hasta <strong>{{ $eventClock->format('d/m/Y H:i:s') }}</strong>. Debe ser posterior al inicio.</p>
            <div hidden aria-hidden="true">
                @foreach($eventCufds as $cufd)
                    @php
                        $limit = $cufd->invalidated_at && $cufd->invalidated_at->lt($cufd->expires_at) ? $cufd->invalidated_at : $cufd->expires_at;
                    @endphp
                    <span data-c2-cufd data-start="{{ $cufd->requested_at->format('Y-m-d\TH:i:s') }}" data-end="{{ $limit->format('Y-m-d\TH:i:s') }}" data-code="{{ $cufd->cufd_code }}" data-requested="{{ $cufd->requested_at->format('d/m/Y H:i:s') }}" data-expires="{{ $cufd->expires_at->format('d/m/Y H:i:s') }}" data-limit="{{ $limit->format('d/m/Y H:i:s') }}"></span>
                @endforeach
            </div>
            <div class="small text-secondary mt-2">El CUFD de recuperación se verifica al registrar el evento y puede renovarse en ese momento.</div>
        @endif
    </div>
</div>
