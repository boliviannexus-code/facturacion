# Superadministrador reservado

`boliviannexus@gmail.com` es la única identidad que puede tener `super_admin` (guard `web`). La comparación del correo ignora mayúsculas y espacios exteriores. La cuenta tiene contexto global aunque conserve una empresa histórica en `users.company_id`; esa asociación no se elimina ni modifica sus ventas.

Las rutas de operación empresarial usan la empresa registrada del usuario, también para el superadministrador. El middleware limita las consultas de esos módulos a esa empresa. Fuera de esos módulos, la cuenta global conserva las funciones de administración de todas las empresas. Las cuentas `admin` operan dentro de su empresa.

Web, API y servicios de usuarios rechazan asignaciones indebidas y actualizaciones de la cuenta protegida por terceros. La cuenta puede modificar su nombre y contraseña, pero no cambiar su correo, desactivarse, eliminarse o perder `super_admin`. Los seeders conservan la contraseña, el nombre y la empresa existentes.

La migración regulariza los superusuarios anteriores: quienes tienen empresa reciben `admin`; quienes no tienen empresa pierden `super_admin` y quedan desactivados. Conserva otros roles, contraseñas e historial. Revoca las sesiones, los tokens personales y el token de recordar sesión de las cuentas regularizadas. Registra los cambios de roles y estado en auditoría sin secretos.

PostgreSQL protege el correo reservado, la permanencia del usuario y el rol, y la pertenencia al rol incluso en asignaciones directas de Spatie o escrituras SQL. Los cambios de esquema requieren los permisos administrativos habituales de PostgreSQL.

Para instalar, ejecutar la migración `2026_10_19_140000_reserve_single_super_administrator` y recargar los workers. En instalaciones nuevas, el seeder crea la cuenta y le asigna el rol. Si una instalación existente no tiene la cuenta reservada, o tiene duplicados o una cuenta reservada desactivada/eliminada, la migración se detiene para evitar bloquear el acceso. Revertir la migración elimina las restricciones, pero no restaura privilegios ni sesiones retiradas.

El superadministrador dispone de un selector «Empresa activa» en la barra superior. Solo permite empresas activas y guarda la elección en la sesión, vinculada a su identidad. Los usuarios ordinarios no pueden usar el selector ni su endpoint. Cambiar de empresa vuelve al panel y no modifica `users.company_id` ni mueve ventas, facturas o cajas. Las consultas, validaciones, credenciales y nuevas operaciones utilizan la empresa seleccionada; las rutas a documentos de otra empresa quedan bloqueadas. La administración de empresas, usuarios, roles y copias de seguridad mantiene el alcance global.

La tabla `company_user_access` conserva las restricciones compuestas de autor y empresa en el historial. Los usuarios ordinarios solo tienen acceso a su empresa registrada; PostgreSQL permite acceso adicional exclusivamente a la identidad reservada con `super_admin`. Nuevas empresas y la asignación inicial del rol sincronizan esos accesos. Los lotes en cola reciben su empresa explícitamente del trabajo persistido y restauran el contexto al terminar, incluso ante errores. Revertir esta migración se bloquea si ya hay historial del superadministrador en otras empresas, evitando perderlo.
