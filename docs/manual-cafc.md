# Facturas manuales de contingencia con CAFC

## Flujo operativo

1. Un usuario con `cafc-ranges.manage` registra la autorización en **Facturación > Rangos CAFC**. El rango puede asignarse a toda una sucursal o a un punto de venta específico.
2. Durante la contingencia, un usuario con `manual-cafc.use` registra cada número físico como **utilizado** o **anulado** y lo relaciona con el evento significativo abierto.
3. El servicio bloquea el rango en base de datos, valida empresa, asignación, vigencia y numeración, y consume el número una sola vez.
4. Una factura utilizada se transcribe copiando cliente, detalle, cantidades, precios, descuentos y total. La fecha original se presenta como solo lectura.
5. Se genera un XML con CAFC y fecha original. XML y GZIP se guardan una sola vez y su hash queda registrado.
6. Cuando el evento significativo queda registrado ante SIAT, el listener encola las facturas manuales pendientes. No se mezclan con los paquetes de facturas digitales fuera de línea.
7. El envío reutiliza el mismo XML y registra intento, respuesta, recepción y estado fiscal. Un intento incierto no se reenvía automáticamente.

## Colas y almacenamiento

- Cola: `siat-manual-cafc`.
- XML: `storage/app/private/manual-invoices/{empresa}/{año}/{mes}`.
- Respaldos: `storage/app/private/manual-invoices/{empresa}/evidence`.
- El adaptador productivo se resuelve mediante `InvoiceSiatClient`; las pruebas sustituyen ese contrato por `SequenceInvoiceSiatClient` y no llaman al SIAT.

## Comandos

```bash
php artisan migrate
php artisan migrate:rollback --step=1
php artisan test --filter=ManualCafcModuleTest
php artisan tinker
```

En Tinker, las relaciones principales pueden verificarse con:

```php
$manual = App\Models\SinManualContingencyInvoice::with(['cafcRange', 'significantEvent', 'items', 'invoice.attempts'])->latest()->first();
$manual?->toArray();
```
