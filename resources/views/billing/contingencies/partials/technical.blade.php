<div class="alert alert-info" role="status">
    <div class="d-flex"><i class="ti ti-shield-lock me-2 fs-2" aria-hidden="true"></i><div><strong>Información de solo lectura</strong><div>Los secretos se ocultan y las respuestas del SIN no pueden modificarse desde esta pantalla.</div></div></div>
</div>

<dl class="authorization-kv mb-3">
    <dt>Tipo</dt><dd>{{ match($type) {'invoice' => 'Factura', 'package' => 'Paquete', 'event' => 'Evento significativo', default => $type} }}</dd>
    <dt>Registro</dt><dd>#{{ $target->id }}</dd>
    <dt>Estado</dt><dd>{{ $target->status_label ?? ($target->package_status?->label() ?? ($target->event_status?->label() ?? '—')) }}</dd>
    <dt>Mensaje</dt><dd>{{ $message ?: 'Sin mensaje técnico registrado.' }}</dd>
</dl>

<h4>Respuesta almacenada</h4>
<pre class="contingency-technical-response" tabindex="0">{{ json_encode($response ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>

<h4 class="mt-4">Intentos de comunicación</h4>
<div class="table-responsive">
    <table class="table table-sm table-vcenter mb-0">
        <thead><tr><th>Intento</th><th>Operación</th><th>Estado</th><th>Duración</th><th>Recepción</th><th>Mensaje</th></tr></thead>
        <tbody>
        @forelse($target->attempts as $attempt)
            <tr><td>#{{ $attempt->attempt_number }}</td><td>{{ $attempt->operation->label() }}</td><td>{{ $attempt->attempt_status->label() }}</td><td>{{ $attempt->duration_ms }} ms</td><td class="font-monospace">{{ $attempt->reception_code ?? '—' }}</td><td>{{ $attempt->message ?? '—' }}</td></tr>
            @foreach($attempt->messages as $responseMessage)<tr class="bg-muted-lt"><td></td><td colspan="5"><span class="badge bg-secondary-lt me-2">{{ $responseMessage->message_code ?? $responseMessage->severity->label() }}</span>{{ $responseMessage->description }}</td></tr>@endforeach
        @empty
            <tr><td colspan="6" class="text-center text-secondary py-3">No hay intentos registrados.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
