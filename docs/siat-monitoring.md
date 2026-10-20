# Monitoreo y alertas de contingencias SIAT

El monitoreo persiste cada condición en `sin_monitoring_alerts`. La llave `active_key` es única mientras la condición está activa: una ejecución posterior actualiza el conteo y la fecha de detección, pero no vuelve a notificar. Cuando la condición desaparece, el registro pasa a `RESOLVED`, libera la llave activa y conserva el historial. Si la condición reaparece, se crea un nuevo ciclo de alerta.

## Canales

- `SIAT_ALERT_INTERNAL`: notificación en la campana de la barra superior.
- `SIAT_ALERT_MAIL`: correo para los roles operativos configurados.
- `SIAT_ALERT_PANEL`: alerta visible en `Facturación > Contingencias`.
- `SIAT_ALERT_TECHNICAL_LOG`: entrada técnica sin credenciales ni respuestas del SIAT.

El correo está desactivado por defecto. Los destinatarios deben estar activos y tener uno de los roles definidos en `siat.monitoring.recipient_roles`.

## Ejecución

En producción deben estar activos tanto el scheduler como los workers de cola:

```bash
php artisan schedule:work
php artisan queue:work --queue=siat-monitoring,siat-recovery,siat-packages,siat-manual-cafc,siat-synchronization,siat-health
```

Para usar cron en lugar de `schedule:work`:

```cron
* * * * * cd /ruta/de/la/aplicacion && php artisan schedule:run >> /dev/null 2>&1
```

Las frecuencias se parametrizan mediante las variables `SIAT_SCHEDULE_*` documentadas en `.env.example`. Cada entrada usa `onOneServer()` y `withoutOverlapping()`. Los jobs también usan unicidad, middleware de exclusión y un lock distribuido del cache configurado.

## Operación

```bash
php artisan schedule:list
php artisan queue:failed
php artisan queue:retry all
```

Para que los locks sean compartidos entre servidores, `CACHE_STORE` debe apuntar a un backend común, por ejemplo Redis o la base de datos. Después de cambiar variables de entorno:

```bash
php artisan config:clear
php artisan config:cache
```
