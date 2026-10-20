# Verificacion de comunicacion SIAT

El modulo separa la orquestacion de la comunicacion del transporte. `SiatCommunicationService`
solo conoce el contrato `SiatCommunicationClient`; no crea clientes SOAP ni realiza llamadas HTTP
directamente. Clasifica cada resultado, aplica reintentos finitos, sanitiza datos sensibles y guarda
un unico `SinCommunicationLog` por verificacion con el numero de intentos y sus duraciones.

## Politica de contingencia

La configuracion predeterminada realiza intentos con esperas de `0`, `2` y `5` segundos. Solo una
secuencia completa de fallos tecnicos reintentables puede recomendar la apertura de una contingencia.
Un timeout aislado, XML rechazado, token/CUIS/CUFD invalido, certificado vencido, error de catalogo,
autenticacion, configuracion o base de datos no la recomienda.

Los valores se pueden cambiar en `config/siat.php`:

```php
'communication' => [
    'timeout_seconds' => 5,
    'retry_delays' => [0, 2, 5],
    'contingency_failure_threshold' => 3,
],
```

No se debe agregar un bucle externo al servicio. El job tiene un solo intento de cola porque los
reintentos controlados pertenecen a `SiatCommunicationService`.

## Adaptador real

La aplicacion registra por defecto esta vinculacion en `AppServiceProvider`:

```php
$this->app->bind(
    SiatCommunicationClient::class,
    SoapSiatCommunicationClient::class,
);
```

`SoapSiatCommunicationClient` usa el `SiatSoapClientFactory` existente y ejecuta
`verificarComunicacion`. Para reemplazarlo por otro transporte real, se crea una clase que implemente
`SiatCommunicationClient` y se cambia solamente esa vinculacion. El nuevo adaptador debe devolver la
respuesta sin alterar o lanzar una excepcion con el detalle tecnico; la clasificacion y los logs siguen
siendo responsabilidad del servicio.

## Cliente simulado en pruebas

Las pruebas vinculan `Tests\Fakes\SequenceSiatCommunicationClient` al mismo contrato:

```php
$this->app->instance(
    SiatCommunicationClient::class,
    new SequenceSiatCommunicationClient([
        ['transaccion' => true],
    ]),
);
```

Tambien sustituyen `SiatDelay` para registrar las esperas sin detener la suite. Por tanto, ninguna
prueba del modulo resuelve ni invoca el adaptador SOAP real.

## Ejecucion asincrona

```php
VerifySiatCommunicationJob::dispatch(
    companyId: $companyId,
    apiTokenId: $tokenId,
    pointOfSaleId: $pointOfSaleId,
    userId: $userId,
);
```

El job es unico temporalmente por empresa, token y punto de venta, se ejecuta despues del commit y
conserva el aislamiento multiempresa al volver a cargar todos sus modelos por `company_id`.
