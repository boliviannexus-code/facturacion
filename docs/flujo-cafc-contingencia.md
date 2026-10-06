# Regularización de facturas manuales CAFC

En Contingencias 2 el orden es:

1. Registrar el CAFC y su punto de venta.
2. Registrar ante el SIN el evento 5, 6 o 7, con su período real ya finalizado.
3. Obtener la aceptación y código de recepción del evento.
4. Registrar y transcribir todas las facturas físicas, conservando sus números y fechas originales.
5. Finalizar el CAFC y enviar los paquetes. Finalizar bloquea nuevas reservas; no se envía automáticamente al transcribir la primera factura.

## Controles

- Registro del evento dentro de las 48 horas posteriores a su finalización.
- Transcripción y envío dentro de las 72 horas posteriores al restablecimiento, representado por el fin del evento.
- Fecha original dentro del período registrado y de la autorización CAFC, sin fechas futuras.
- Evento aceptado, con recepción, perteneciente al mismo CAFC, empresa, sucursal y punto de venta.
- CUFD histórico vigente al inicio del evento. Su código de control genera el CUF de las transcripciones.
- CUFD de recuperación vigente para registrar el evento y enviar los paquetes. Se solicita si falta uno obtenido después de la recuperación.
- Todas las facturas reservadas deben estar transcritas antes de finalizar, exceptuando números anulados.
- CUFD, código de control, fecha y CUF se verifican antes de finalizar, al formar paquetes y antes de enviarlos.
- Los paquetes se forman por el alcance fiscal correspondiente y conservan el límite existente de 500 facturas.
- El flujo automático de pruebas CAFC registra el evento antes de transcribir; puede continuar tras un reintento sin crear otras facturas ya transcritas.

Los registros del flujo anterior que ya tienen XML no se reasocian automáticamente a un evento nuevo ni se reescriben. En pruebas se puede retirar el CAFC y repetir el circuito; se conserva el historial. Un rechazo del SIN debe conciliarse según su respuesta, conservando la identidad fiscal.

Estos controles validan los datos declarados y la aceptación del SIN. La fecha, hora, causa del evento y contenido del documento físico deben reflejar lo ocurrido realmente.

## Fuentes oficiales consultadas

- [SIN: Emisión y envío, paquetes por contingencia](https://siatinfo.impuestos.gob.bo/index.php/facturacion-en-linea/emision-y-envio-de-facturas/emision-y-envio).
- [SIN: Contingencia y eventos significativos](https://siatinfo.impuestos.gob.bo/index.php/facturacion-en-linea/emision-y-envio-de-facturas/contingencia-y-eventos-significativos).
- [SIN: Cartilla del aplicativo de escritorio, plazos de 48 y 72 horas](https://sac.impuestos.gob.bo/formularios/pdf/CARTILLA%20APP%20DE%20ESCRITORIO%2009-23.pdf).

Consulta: 3 de octubre de 2026.
