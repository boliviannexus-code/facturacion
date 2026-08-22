# Arquitectura de facturacion y contingencias SIAT

## Diagnostico actual

El sistema usa Laravel 13, PostgreSQL y Blade. La separacion multiempresa se aplica mediante
`CompanyContext` y el scope global `BelongsToCompany`. Las entidades fiscales y comerciales
existentes son:

- Empresa: `Company`.
- Sucursal y punto de venta: `SinBranch` y `SinPointOfSale`.
- Credenciales fiscales: `SinApiToken` y `SinAuthorization`.
- Codigos: `SinCuis`, `SinCufd` y `SiatCufGenerator`.
- Facturacion: `SinInvoiceIssue`, `PurchaseSaleInvoiceIssueService`, constructor XML y PDF.
- Contingencias: `SinSignificantEvent` y `SignificantEventService`.
- Clientes y catalogo interno: `Customer`, `Product` y `ProductCategory`.
- Usuario y caja: `User` y `CashRegister`.

No existen actualmente modelos de venta, pago, inventario, almacen, paquete de contingencia,
intento de envio fiscal o certificado digital. Tampoco existen jobs ni comandos Artisan de
recuperacion SIAT. Esos componentes no deben simularse usando `SinInvoiceIssue`.

El XML, GZIP, hash, CUF, CUFD, payload y respuesta se conservan al crear `SinInvoiceIssue`.
El XML no se reconstruye para imprimir: el PDF usa el snapshot persistido en `payload`.

## Brechas detectadas

Antes de esta fase, el estado fiscal dependia de `status_label` y `transaccion`. Toda excepcion
SOAP quedaba como `Error`, por lo que un error interno podia ofrecer registro de contingencia.
Ademas, el siguiente numero se calculaba solo desde facturas validadas y podia reutilizar el
numero de un envio incierto.

## Fase 1 implementada

- Modos de emision tipados: `ONLINE`, `OFFLINE_DIGITAL`, `MANUAL_CAFC`, `PORTAL_WEB`, `BLOCKED`.
- Estados comercial y fiscal separados.
- Clasificacion tecnica de fallos, independiente de mensajes/codigos oficiales del SIAT.
- Un timeout posterior al inicio del envio queda como `UNCERTAIN_SEND`.
- Solo categorias tecnicas elegibles permiten registrar un evento significativo.
- La numeracion toma el ultimo intento persistido para no reutilizar un numero incierto.
- Listado y filtro de facturas usan el estado fiscal nuevo.

Los campos heredados `status_label`, `status_code` y `transaccion` se mantienen por
compatibilidad y trazabilidad; no son la fuente principal del nuevo flujo.

## Estado actual

Ya estan implementados los intentos SIAT, emision digital fuera de linea, eventos significativos,
recuperacion, paquetes de hasta 500 XML, validacion posterior, CAFC manual, auditoria, monitor,
alertas y scheduler. Los adaptadores SOAP se sustituyen por fakes en pruebas.

Permanecen como tareas operativas la conciliacion de respuestas inciertas, la confirmacion de los
codigos del catalogo SIAT por ambiente y la instalacion de workers/scheduler supervisados en
produccion. La modalidad es facturacion computarizada en linea; el firmador no agrega firma
digital porque esa modalidad no corresponde a facturacion electronica en linea.
