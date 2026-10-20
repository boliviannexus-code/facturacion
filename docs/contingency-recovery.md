# Recuperación de contingencias SIAT

El proceso se ejecuta periódicamente con:

```bash
php artisan siat:recover-open-contingencies
```

También puede limitarse por empresa o evento:

```bash
php artisan siat:recover-open-contingencies --company=10
php artisan siat:recover-open-contingencies --event=25
```

El comando está programado cada minuto y no permite ejecuciones solapadas. Para cada evento abierto verifica la comunicación, conserva `started_at`, guarda la recuperación real en `ended_at` y `recovery_detected_at`, y encola `RegisterSignificantEventJob`.

El mismo proceso puede iniciarse manualmente desde **Facturación > Contingencias** mediante
**Registrar evento significativo**. La acción está disponible para eventos `OPEN` y exige seleccionar
uno de los eventos oficiales sincronizados en el catálogo `eventos_significativos`, además de registrar
la descripción real. Después verifica que la comunicación se haya recuperado, fija la fecha real de
finalización y ejecuta inmediatamente el SOAP `registroEventoSignificativo`. La cola de recuperación
queda como respaldo para reintentos automáticos, pero la acción manual no depende de un worker para
obtener la respuesta de SIAT. Si SIAT aún no está disponible, el evento permanece sin registrar y no se
generan ni envían paquetes.

El job solicita un CUFD nuevo usando explícitamente la empresa, token, autorización, CUIS, sucursal y punto de venta del evento. Luego registra el evento mediante `SignificantEventRegistrar`. Los reintentos usan las demoras configuradas en `siat.contingency_recovery.registration_backoff` y nunca cierran el evento. El cierre solo corresponde al proceso posterior que valide todas sus facturas.

Cada llamada de registro genera un `SinSiatAttempt` y conserva su respuesta y mensajes. Un claim transaccional evita llamadas simultáneas. Al registrarse correctamente, `SignificantEventRegistered` inicia la cola de empaquetado de sus facturas offline.

El SOAP invoca `registroEventoSignificativo` con `SolicitudEventoSignificativo` y los campos oficiales
`codigoMotivoEvento`, `fechaHoraInicioEvento`, `fechaHoraFinEvento`, `cufd` (nuevo CUFD), `cufdEvento` (CUFD usado
durante la contingencia), sucursal y punto de venta. El sistema solo cambia el evento a `REGISTERED`
cuando la respuesta contiene simultáneamente `transaccion=true` y un `codigoRecepcion` no vacío. Sin
ambas evidencias no se dispara el empaquetado.

Si el worker termina durante la llamada y el claim supera `SIAT_EVENT_CLAIM_TTL_MINUTES`, el intento pasa a `UNCERTAIN`, el evento queda `FAILED` con revisión manual y no se reenvía automáticamente. Un responsable autorizado puede conciliar el caso y ordenar explícitamente el reintento.

## Adaptadores

- `RecoveryCufdProvider`: obtención del nuevo CUFD.
- `SignificantEventRegistrar`: registro del evento significativo.
- `SiatRecoveryCufdProvider` y `SoapSignificantEventRegistrar`: adaptadores reales SOAP.

Las pruebas reemplazan ambos contratos por secuencias simuladas, por lo que nunca llaman al SIAT real.

## Corrección administrativa

Los eventos no registrados pueden corregirse indicando usuario, motivo y el valor autorizado:

```bash
php artisan siat:recover-open-contingencies \
  --event=25 \
  --actor=7 \
  --event-code=<CODIGO_AUTORIZADO> \
  --description="Descripcion corregida" \
  --reason="Motivo documentado"
```

La fecha original de inicio y la recuperación no se pueden modificar. La corrección conserva responsable, motivo y fecha en columnas explícitas y en la auditoría del modelo. Los eventos registrados o cerrados no admiten correcciones.
