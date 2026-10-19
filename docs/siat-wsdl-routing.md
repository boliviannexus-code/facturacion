# Servicios SIAT por empresa y ambiente

Cada servicio se identifica por empresa, ambiente (1: Producción; 2: Piloto) y clave. Las claves utilizadas son `codes`, `operations`, `synchronization`, `purchase_sale_invoice` y `zero_rate_invoice`. Los registros prevalorados conservan su configuración, aunque su soporte de emisión depende de los documentos sector implementados.

El menú **Servicios WSDL** permite administrar ambos ambientes y revisar registros **Sin clasificar**. La autorización SIN determina el ambiente de las operaciones nuevas. El selector del menú no cambia esa autorización. Los clientes de facturas y paquetes usan el ambiente guardado en el documento; la recuperación de eventos utiliza el ambiente del CUFD histórico.

`SiatWsdlResolver::resolve(companyId, environment, key)` consulta exclusivamente el registro activo de esa empresa y ambiente. Rechaza servicios faltantes, inactivos, eliminados, sin clasificar o con host incompatible. No hay fallback de piloto ni construcción de enlaces desde el token. Se conserva la ruta completa configurada, tanto `/sfd/` como `/v2/`.

Solo se aceptan URLs HTTPS de los hosts oficiales: `siatrest.impuestos.gob.bo` para producción y `pilotosiatservicios.impuestos.gob.bo` para piloto. Las URLs no pueden incluir credenciales ni fragmentos. El QR se selecciona centralmente según el ambiente histórico de la factura.

## Migración y puesta en funcionamiento

1. Ejecutar las migraciones de ambiente WSDL y trazabilidad de comunicación.
2. Revisar **Sin clasificar**: los hosts desconocidos no se utilizan. Corregir su URL y asignar ambiente.
3. Revisar y activar explícitamente los servicios necesarios de producción. Las plantillas se crean inactivas; tasa cero usa `ServicioFacturacionComputarizada`.
4. Configurar la autorización fiscal y credenciales para el ambiente requerido. El cambio de autorización conserva el mecanismo existente de invalidación de CUIS/CUFD; esta migración no invalida credenciales ni modifica documentos.
5. Reiniciar los procesos de cola al desplegar el código para que carguen los nuevos clientes y contratos.

El campo WSDL del token se conserva por compatibilidad histórica y deja de editarse y utilizarse para enrutar solicitudes. Los nuevos intentos registran la URL resuelta y el ambiente; nunca el token.

Las pruebas usan dobles SOAP. La implementación y la migración no emiten ni reenvían facturas, ni activan producción.
