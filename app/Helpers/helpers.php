<?php

if (! function_exists('money_format_decimal')) {
    function money_format_decimal(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }
}

if (! function_exists('role_label')) {
    function role_label(string $name): string
    {
        $labels = [
            'admin' => 'Administrador',
            'super_admin' => 'Super administrador',
            'manager' => 'Gerente',
            'cashier' => 'Cajero',
            'viewer' => 'Visualizador',
            'branch_admin' => 'Administrador de sucursal',
            'accounting' => 'Contabilidad',
            'tax_responsible' => 'Responsable tributario',
            'technical_support' => 'Soporte técnico',
        ];

        return $labels[$name] ?? str($name)->replace(['-', '_'], ' ')->headline()->toString();
    }
}

if (! function_exists('permission_module_label')) {
    function permission_module_label(string $module): string
    {
        $labels = [
            'dashboard' => 'Panel principal',
            'users' => 'Usuarios',
            'roles' => 'Roles',
            'permissions' => 'Permisos',
            'companies' => 'Empresas',
            'audits' => 'Auditoria',
            'cash-registers' => 'Cajas',
            'invoices' => 'Facturacion',
            'product-categories' => 'Categorias',
            'customers' => 'Clientes',
            'products' => 'Productos',
            'sin-authorizations' => 'Autorizacion SIN',
            'backups' => 'Copias de seguridad',
            'invoice-tests' => 'Pruebas de facturación',
            'cafc-ranges' => 'Rangos CAFC',
            'manual-cafc' => 'Facturación manual CAFC',
            'contingencies' => 'Contingencias',
            'sin-api-tokens' => 'Credenciales del SIN',
            'siat-communication' => 'Comunicación con Impuestos',
            'siat-cuis' => 'Códigos CUIS',
            'siat-catalogs' => 'Catálogos del SIAT',
            'siat-branches' => 'Sucursales del SIAT',
        ];

        return $labels[$module] ?? str($module)->replace(['-', '_'], ' ')->headline()->toString();
    }
}

if (! function_exists('permission_action_label')) {
    function permission_action_label(string $action): string
    {
        $labels = [
            'view' => 'Ver',
            'create' => 'Crear',
            'edit' => 'Editar',
            'update' => 'Actualizar',
            'delete' => 'Eliminar',
            'restore' => 'Restaurar',
            'change-password' => 'Cambiar contrasena',
            'assign-roles' => 'Asignar roles',
            'assign-permissions' => 'Asignar permisos',
            'manage' => 'Administrar',
            'open' => 'Abrir',
            'close' => 'Cerrar',
            'issue' => 'Emitir',
            'run' => 'Ejecutar',
            'cancel' => 'Anular',
            'download' => 'Descargar',
            'use' => 'Utilizar',
            'transcribe' => 'Transcribir',
            'communication.check' => 'Verificar comunicación',
            'events.view' => 'Ver eventos',
            'events.retry' => 'Reintentar eventos',
            'packages.build' => 'Generar paquetes',
            'packages.send' => 'Enviar paquetes',
            'packages.validate' => 'Validar paquetes',
            'artifacts.download' => 'Descargar archivos',
            'technical.view' => 'Ver información técnica',
            'audit.view' => 'Ver auditoría',
            'verify' => 'Verificar',
            'request' => 'Solicitar',
            'sync' => 'Sincronizar',
        ];

        return $labels[$action] ?? str($action)->replace(['-', '_'], ' ')->headline()->toString();
    }
}

if (! function_exists('permission_label')) {
    function permission_label(string $name): string
    {
        if (! str_contains($name, '.')) {
            return permission_action_label($name);
        }

        [$module, $action] = explode('.', $name, 2);

        return permission_module_label($module).': '.permission_action_label($action);
    }
}

if (! function_exists('permission_action_description')) {
    function permission_action_description(string $action): string
    {
        $descriptions = [
            'view' => 'Puede consultar el listado y el detalle.',
            'create' => 'Puede registrar nuevos elementos.',
            'edit' => 'Puede modificar la informacion existente.',
            'update' => 'Puede modificar la informacion existente.',
            'delete' => 'Puede eliminar elementos.',
            'restore' => 'Puede recuperar elementos eliminados.',
            'change-password' => 'Puede establecer una nueva contrasena.',
            'assign-roles' => 'Puede cambiar los roles de los usuarios.',
            'assign-permissions' => 'Puede configurar los accesos de los roles.',
            'manage' => 'Tiene control completo sobre este modulo.',
            'approve' => 'Puede revisar y aprobar solicitudes.',
            'open' => 'Puede abrir una caja propia.',
            'close' => 'Puede cerrar su caja activa.',
            'issue' => 'Puede preparar y emitir facturas.',
            'run' => 'Puede ejecutar el proceso.',
            'cancel' => 'Puede anular facturas emitidas.',
            'download' => 'Puede descargar archivos.',
            'use' => 'Puede utilizar el recurso.',
            'transcribe' => 'Puede transcribir la información registrada.',
            'communication.check' => 'Puede comprobar la comunicación con Impuestos.',
            'events.view' => 'Puede consultar los eventos significativos.',
            'events.retry' => 'Puede reintentar el registro de eventos.',
            'packages.build' => 'Puede generar paquetes de facturas.',
            'packages.send' => 'Puede enviar paquetes a Impuestos.',
            'packages.validate' => 'Puede validar el estado de los paquetes.',
            'artifacts.download' => 'Puede descargar los archivos generados.',
            'technical.view' => 'Puede consultar información técnica.',
            'audit.view' => 'Puede consultar la auditoría de contingencias.',
            'verify' => 'Puede verificar la conexión con el servicio.',
            'request' => 'Puede solicitar un nuevo código.',
            'sync' => 'Puede sincronizar la información con Impuestos.',
        ];

        return $descriptions[$action] ?? 'Permite ejecutar la accion '.str($action)->replace(['-', '_'], ' ')->lower()->toString().'.';
    }
}

if (! function_exists('authentication_context_label')) {
    function authentication_context_label(string $context): string
    {
        return match ($context) {
            'web' => 'Aplicación web',
            'api' => 'API',
            default => str($context)->replace(['-', '_'], ' ')->headline()->toString(),
        };
    }
}
