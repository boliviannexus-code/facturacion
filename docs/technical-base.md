# Base Administrativa - Base Tecnica

## Stack

- PHP 8.3+
- Laravel 13
- Laravel Sail con runtime PHP 8.5
- PostgreSQL 17
- Redis 8
- Docker Compose
- Blade + Bootstrap 5
- CoreUI
- Laravel Sanctum
- Spatie Laravel Permission

## Arquitectura inicial

La base sigue MVC con controladores delgados, validacion por Form Requests, salida API mediante Resources, reglas de autorizacion con Policies, logica de negocio en Services y acceso a datos en Repositories.

```txt
app/
  Http/Controllers/Web
  Http/Controllers/Api/V1
  Http/Requests
  Http/Resources
  Services
  Repositories
  Policies
  Traits
  Enums
  Helpers
resources/views/
  components/admin
  components/ui
  layouts
```

## Modulos implementados

- Autenticacion web inicial.
- Autenticacion API por token Sanctum.
- Roles y permisos iniciales.
- Empresas con CRUD web.
- Auditoria basica.
- Apertura, cierre e historial de cajas por usuario y empresa.
- Parametros multiempresa para productos, categorias, medidas y clientes.
- Parametros de autorizacion SIN/SIAT por empresa.
- Dashboard administrativo base.
- Layout administrativo CoreUI reutilizable.
- Componentes Blade para sidebar, header, alerts, cards, stats, tablas y formularios.

## Frontend administrativo

El panel usa CoreUI sobre Bootstrap 5 mediante Vite. La estructura esta preparada para Livewire futuro porque el layout centraliza el shell visual y los componentes Blade encapsulan piezas repetibles.

- `resources/views/layouts/admin.blade.php`: shell administrativo.
- `resources/views/components/admin/sidebar.blade.php`: sidebar responsive.
- `resources/views/components/admin/header.blade.php`: navbar superior.
- `resources/views/components/admin/flash.blade.php`: alertas del sistema.
- `resources/views/components/ui/card.blade.php`: tarjeta base.
- `resources/views/components/ui/table-card.blade.php`: tabla responsive.
- `resources/views/components/ui/form-panel.blade.php`: panel de formulario.
- `resources/views/components/ui/stat-card.blade.php`: metrica de dashboard.

## Usuario inicial

```txt
Email: boliviannexus@gmail.com
Password: configurado en `DatabaseSeeder`
Rol: super_admin
```

## Formato API

Todas las respuestas controladas usan:

```json
{
  "success": true,
  "message": "Operación realizada correctamente",
  "data": {}
}
```

## Rutas principales

Web:

- `GET /login`
- `POST /login`
- `POST /logout`
- `GET /`
- `resource /companies`
- `resource /users`
- `resource /roles`
- `resource /permissions`
- `GET /audits`
- `GET /cash-registers`
- `POST /cash-registers`
- `PATCH /cash-registers/{cashRegister}/close`
- `GET /parameters/categories`
- `GET /parameters/measurement-units`
- `GET /parameters/customers`
- `GET /parameters/products`
- `GET /parameters/authorization`
- `POST /parameters/authorization`
- `PUT /parameters/authorization`

## Parametros tributarios base

El menu `Parametros` agrupa submodulos independientes y aislados por empresa:

- `Categorias`: catalogo interno para clasificar productos. No sustituye el catalogo SIN de productos y servicios.
- `Medidas`: registra `siat_code`, equivalente al `codigoClasificador` de la parametrica SIAT `sincronizarParametricaUnidadMedida`, y `description`, equivalente a `descripcion`.
- `Clientes`: registra los datos necesarios para alimentar el XML de factura de compra y venta: codigo tipo documento identidad, numero documento, complemento, codigo cliente y nombre/razon social.
- `Productos`: registra el codigo interno, descripcion, actividad economica, codigo de producto SIN homologado, categoria interna, unidad de medida y precio unitario predeterminado.
- `Autorizacion`: registra una configuracion por empresa con `nit`, razon social, `codigoSistema`, `codigoAmbiente`, `codigoModalidad`, `codigoSucursal` y `codigoPuntoVenta`. El `codigoSistema` se almacena cifrado y queda excluido de auditoria en claro.

No se cargan codigos oficiales por semilla local. Las listas oficiales deben venir luego desde sincronizacion SIAT para evitar valores inventados.

API:

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `apiResource /api/v1/users`
- `apiResource /api/v1/roles`
- `apiResource /api/v1/permissions`

## Modulos retirados para reconstruccion

Se retiraron artefactos incompletos de alojamientos, sucursales, almacenes, ventas, POS, compras, inventario, metodos de pago y reportes para evitar dependencias rotas. Esos dominios deben reingresar mediante migraciones, modelos, servicios, rutas y pruebas nuevas cuando se defina la arquitectura modular de facturacion.

## Siguiente paso recomendado

Crear desde cero los modulos comerciales minimos antes de integrar SIAT.

## Entorno local

La infraestructura de desarrollo se define unicamente en `compose.yaml` y se
opera mediante Laravel Sail:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test
```

Los servicios internos se resuelven mediante los hosts `pgsql`, `redis` y
`mailpit`. El proyecto no usa un `Dockerfile` propio. Los comandos Artisan se
ejecutan con `./vendor/bin/sail artisan`; la ejecucion directa con `php artisan`
desde el host no forma parte del entorno soportado.
