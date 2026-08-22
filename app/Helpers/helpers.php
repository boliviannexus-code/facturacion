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
        ];

        return $descriptions[$action] ?? 'Permite ejecutar la accion '.str($action)->replace(['-', '_'], ' ')->lower()->toString().'.';
    }
}
