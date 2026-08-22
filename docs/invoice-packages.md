# Paquetes de facturas de contingencia SIAT

El registro exitoso de un evento significativo dispara `BuildContingencyPackagesJob`. El proceso usa un único servicio de dominio, `InvoicePackageService`, y separa tres operaciones idempotentes:

1. `buildForEvent`: selecciona solo facturas `OFFLINE_DIGITAL` no procesadas y crea archivos `.tar.gz` de hasta 500 XML originales.
2. `send`: transmite el archivo mediante `InvoicePackageSiatClient` y conserva el código de recepción. La recepción deja el paquete en `PENDING_VALIDATION`; nunca equivale a validación.
3. `checkValidation`: consulta el código de recepción, actualiza el paquete y aplica a cada factura el resultado individual devuelto por SIAT.

No se mezclan empresa/NIT, evento, sucursal, punto de venta, sector ni tipo de documento. Las facturas manuales y las ya validadas se excluyen. El hash SHA-256, tamaño, cantidad, alcance y asociaciones del archivo generado quedan protegidos en la base de datos.

## Colas

Los workers deben escuchar la cola `siat-packages`:

```bash
php artisan queue:work --queue=siat-packages --tries=10
```

Los jobs son:

- `BuildContingencyPackagesJob`
- `SendContingencyPackageJob`
- `CheckPackageValidationJob`

Los reintentos son finitos y se configuran en `siat.packages`. Un fallo comprobado antes de transmitir conserva `PENDING_SEND` y permite reintentar. Un timeout o respuesta perdida que pudo ocurrir después de transmitir deja el paquete en `SENT`, evita el reenvío automático y requiere conciliación administrativa para no duplicarlo.

Si el worker termina abruptamente con un envío en curso, el `claim` vencido se convierte en un intento `UNCERTAIN` y el paquete queda en `SENT`; no se reutiliza el claim para transmitir otra vez. `SIAT_PACKAGE_CLAIM_TTL_MINUTES` define cuándo se considera abandonado. Los estados `FAILED`, reservados para fallos sin transmisión confirmada, sí admiten un reintento administrativo.

El evento solo llega a `COMPLETED` cuando todos sus paquetes y todas sus facturas digitales tienen un resultado final.

## Adaptador real y pruebas

`InvoicePackageSiatClient` desacopla el dominio del transporte. En producción, `AppServiceProvider` enlaza el contrato con `SoapInvoicePackageSiatClient`, que usa `recepcionPaqueteFactura` y `validacionRecepcionPaqueteFactura`.

Las pruebas sustituyen el contrato por `SequenceInvoicePackageSiatClient`; no acceden al SIAT. Para cambiar de transporte debe implementarse el mismo contrato y reemplazarse únicamente su binding en el proveedor.
