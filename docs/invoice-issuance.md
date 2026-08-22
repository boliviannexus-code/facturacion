# Servicio principal de emision

`InvoiceIssuanceService::issue(Sale $sale)` es el unico punto que crea y envia facturas. El antiguo
`PurchaseSaleInvoiceIssueService` se conserva como fachada de compatibilidad, pero delega toda la
operacion al servicio principal.

## Limites transaccionales

1. `SaleCreationService` guarda la venta y una copia de sus productos usando la hora del servidor.
2. El emisor bloquea la venta con `FOR UPDATE`, reserva el numero en `sin_invoice_sequences`, genera
   CUF/XML y crea la factura dentro de una transaccion.
3. La llamada SOAP ocurre fuera de la transaccion para no mantener locks durante una operacion de red.
4. La respuesta, mensajes, intento SIAT e historial fiscal se guardan en una nueva transaccion.

Existe una restriccion unica entre empresa y venta, otra sobre la secuencia fiscal y un trigger que
impide modificar numero, CUF, fecha o rutas/hash XML despues de crear la factura. Un timeout posterior
al envio produce `UNCERTAIN_SEND`; las llamadas repetidas devuelven la misma factura y no vuelven a
enviarla automaticamente.

## Decision de emision

- `ONLINE`: comunicacion disponible, CUIS y CUFD utilizables.
- `OFFLINE_DIGITAL`: falla tecnica confirmada que habilita contingencia y CUFD utilizable.
- `MANUAL_CAFC_REQUIRED`: contingencia habilitada, sin CUFD utilizable y con rango CAFC disponible.
- `BLOCKED`: configuracion invalida, error no habilitado para contingencia o ausencia de CUFD/CAFC.

La emision offline crea o reutiliza un evento abierto, conserva XML y PDF inmutables, usa la fecha real
de la venta, queda en `OFFLINE_ISSUED` y despacha `SynchronizeOfflineInvoiceJob`. El job no valida ni
envia la factura individualmente: espera que el evento permita iniciar el futuro proceso de paquetes.

La falta de comunicacion no obliga al operador a registrar el evento antes de facturar. La interfaz
informa la caida, cambia la accion a **Emitir fuera de linea** y permite emitir todas las facturas que
sean necesarias mientras exista un CUFD utilizable. Cuando la comunicacion se recupera, la emision
normal queda bloqueada por punto de venta hasta registrar el evento y dar un resultado final a todas
las facturas digitales offline. El registro y procesamiento se administran como un proceso separado
desde el modulo de contingencias.

## Transporte y firma

`InvoiceSiatClient` permite sustituir `SoapInvoiceSiatClient` en pruebas. Las excepciones de transporte
indican si la solicitud pudo haber llegado al SIN, evitando reenvios inciertos. `InvoiceXmlSigner`
permite incorporar otra modalidad; para Facturacion Computarizada en Linea el adaptador actual retorna
el XML sin firma digital.

## Efectos comerciales

El proyecto aun no posee tablas de existencias ni pagos. `SaleCommercialEffectService` registra una
sola vez `inventory_applied_at`, `payment_registered_at` y `commercial_confirmed_at` bajo el lock de la
venta. Estos marcadores son los puntos idempotentes que deberan envolver los movimientos reales cuando
se incorporen dichos modulos.
