<div class="row g-3">
    <div class="col-12 col-lg-7">
        <section aria-labelledby="event-summary-heading">
            <h3 class="h4" id="event-summary-heading">{{ $event->event_description }}</h3>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge bg-primary-lt">Evento #{{ $event->id }}</span>
                <span class="badge bg-secondary-lt">Código {{ $event->event_code }}</span>
                <span class="badge {{ $event->transaccion ? 'bg-success-lt' : 'bg-warning-lt' }}">{{ $event->event_status->label() }}</span>
            </div>
            <dl class="authorization-kv">
                <dt>Empresa</dt><dd>{{ $event->company?->name }}</dd>
                <dt>Sucursal</dt><dd>{{ $event->branch?->display_name }}</dd>
                <dt>Punto de venta</dt><dd>{{ $event->pointOfSale?->display_name }}</dd>
                <dt>Inicio original</dt><dd>{{ $event->started_at->format('d/m/Y H:i:s') }}</dd>
                <dt>Recuperación detectada</dt><dd>{{ $event->recovery_detected_at?->format('d/m/Y H:i:s') ?? 'Pendiente' }}</dd>
                <dt>Duración</dt><dd>{{ $event->started_at->diffForHumans($event->recovery_detected_at ?? now(), true) }}</dd>
                <dt>Plazo registrado</dt><dd>{{ $event->expires_at?->format('d/m/Y H:i:s') ?? 'Sin plazo registrado' }}</dd>
                <dt>Código de recepción</dt><dd class="font-monospace text-break">{{ $event->reception_code ?? 'Pendiente' }}</dd>
                <dt>Ambiente SIAT</dt><dd>{{ $event->authorization?->environment_code?->label() ?? 'No identificado' }}</dd>
                <dt>Registrado por</dt><dd>{{ $event->registrar?->name ?? 'Sistema / pendiente' }}</dd>
                <dt>Mensaje</dt><dd>{{ $event->message ?? 'Sin mensaje' }}</dd>
            </dl>
        </section>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card bg-muted-lt border-0">
            <div class="card-body">
                <h4 class="card-title">Documentos relacionados</h4>
                <dl class="row mb-0">
                    <dt class="col-8 text-secondary">Facturas digitales</dt><dd class="col-4 text-end fw-semibold">{{ $event->invoiceIssues->where('emission_mode', \App\Enums\InvoiceEmissionMode::OfflineDigital)->count() }}</dd>
                    <dt class="col-8 text-secondary">Facturas manuales</dt><dd class="col-4 text-end fw-semibold">{{ $event->manualInvoices->count() }}</dd>
                    <dt class="col-8 text-secondary">Paquetes</dt><dd class="col-4 text-end fw-semibold">{{ $event->packages->count() }}</dd>
                    <dt class="col-8 text-secondary">Intentos SIAT</dt><dd class="col-4 text-end fw-semibold">{{ $event->attempts->count() }}</dd>
                </dl>
            </div>
        </div>
        @if($event->manual_review_required)
            <div class="alert alert-warning mt-3 mb-0" role="alert"><strong>Revisión administrativa requerida</strong><div>{{ $event->administrative_correction_reason ?: 'El evento requiere intervención del responsable tributario.' }}</div></div>
        @endif
        @if($event->authorization?->environment_code === \App\Enums\SiatEnvironment::Production && $event->attempts->contains(fn ($attempt) => str_contains(mb_strtolower((string) $attempt->endpoint), 'pilotosiat')))
            <div class="alert alert-danger mt-3 mb-0" role="alert">
                <strong>Registro enviado al ambiente incorrecto</strong>
                <div>La autorización es de Producción, pero existe un intento dirigido a Piloto. Este registro no aparecerá en el ambiente productivo de Impuestos y requiere conciliación.</div>
            </div>
        @endif
    </div>
    <div class="col-12">
        @php
            $canRegularize = ! $event->transaccion
                && in_array($event->event_status, [
                    \App\Enums\SignificantEventStatus::RecoveryDetected,
                    \App\Enums\SignificantEventStatus::PendingRegistration,
                    \App\Enums\SignificantEventStatus::Failed,
                ], true)
                && ($event->manual_review_required || ! $event->ended_at || $event->ended_at->lessThanOrEqualTo($event->started_at));
        @endphp
        @can('contingencies.events.retry')
            @if($canRegularize)
                <div class="card border-warning mb-3">
                    <div class="card-body">
                        <h4 class="card-title mb-2"><i class="ti ti-tool me-1" aria-hidden="true"></i>Regularización administrativa</h4>
                        <p class="text-secondary mb-3">Corrige un intervalo de duración cero, descarta el CUFD de recuperación rechazado y vuelve a registrar este mismo evento. La factura original no se duplica y el cambio queda auditado.</p>
                        <form method="POST" action="{{ route('billing.contingencies.events.regularize', $event) }}"
                              data-confirm-title="¿Regularizar y reenviar el evento?"
                              data-confirm-text="Se corregirá el intervalo fiscal, se solicitará un nuevo CUFD de recuperación y se reenviará el evento al SIAT."
                              data-confirm-button="Sí, regularizar y reenviar">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="regularization-reason-{{ $event->id }}">Motivo de la regularización</label>
                                <textarea class="form-control" id="regularization-reason-{{ $event->id }}" name="reason" rows="2" minlength="10" maxlength="500" required>Corrección del intervalo inválido y renovación del CUFD de recuperación.</textarea>
                            </div>
                            <label class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="confirmation" value="1" required>
                                <span class="form-check-label">Revisé el resultado anterior y autorizo un nuevo intento de registro.</span>
                            </label>
                            <button class="btn btn-warning" type="submit">
                                <i class="ti ti-refresh-alert me-1" aria-hidden="true"></i>Corregir y reintentar
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        @endcan
        <div class="alert alert-info" role="status">
            <strong>Flujo de regularización:</strong>
            el evento debe obtener un código de recepción de SIAT antes de generar los paquetes. Después, los paquetes se envían y consultan hasta obtener el resultado final de cada factura.
        </div>
        <h4>Paquetes del evento</h4>
        <div class="table-responsive">
            <table class="table table-sm table-vcenter mb-0"><thead><tr><th>Número</th><th>Facturas</th><th>Estado</th><th>Recepción</th><th>Última actualización</th><th class="text-end">Acciones</th></tr></thead><tbody>
            @forelse($event->packages as $package)
                <tr>
                    <td>#{{ $package->package_number }}</td><td>{{ $package->invoice_count }}</td><td>{{ $package->package_status->label() }}</td><td class="font-monospace">{{ $package->reception_code ?? '—' }}</td><td>{{ $package->updated_at->format('d/m/Y H:i:s') }}</td>
                    <td class="text-end">
                        <div class="btn-list justify-content-end">
                            @can('contingencies.packages.send')
                                @if(in_array($package->package_status, [\App\Enums\InvoicePackageStatus::Created, \App\Enums\InvoicePackageStatus::PendingSend, \App\Enums\InvoicePackageStatus::Failed], true))
                                    <form method="POST" action="{{ route('billing.contingencies.packages.send', $package) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit">{{ $package->package_status === \App\Enums\InvoicePackageStatus::Failed ? 'Reintentar envío' : 'Enviar al SIN' }}</button></form>
                                @endif
                            @endcan
                            @can('contingencies.packages.validate')
                                @if(in_array($package->package_status, [\App\Enums\InvoicePackageStatus::Sent, \App\Enums\InvoicePackageStatus::PendingValidation, \App\Enums\InvoicePackageStatus::Observed], true))
                                    <form method="POST" action="{{ route('billing.contingencies.packages.validate', $package) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit">Consultar resultado</button></form>
                                @endif
                            @endcan
                            @can('contingencies.technical.view')<a class="btn btn-icon btn-ghost-secondary" href="{{ route('billing.contingencies.technical.show', ['package', $package->id]) }}" data-modal-url="{{ route('billing.contingencies.technical.show', ['package', $package->id]) }}" data-modal-title="Respuesta técnica del paquete" aria-label="Ver respuesta técnica"><i class="ti ti-code" aria-hidden="true"></i></a>@endcan
                        </div>
                    </td>
                </tr>
            @empty<tr><td colspan="6" class="text-center text-secondary py-3">Todavía no se generaron paquetes. Si solicitaste la generación, comprueba que el procesador de cola esté activo.</td></tr>@endforelse
            </tbody></table>
        </div>
    </div>
    @can('contingencies.audit.view')
        @can('audits.view')
            <div class="col-12 text-end"><a class="btn btn-outline-secondary btn-sm" href="{{ route('audits.index', ['auditable_type' => \App\Models\SinSignificantEvent::class]) }}"><i class="ti ti-history me-1" aria-hidden="true"></i>Ver auditoría de eventos</a></div>
        @endcan
    @endcan
</div>
