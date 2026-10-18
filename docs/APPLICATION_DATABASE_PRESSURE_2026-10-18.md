# Aplicaciones y saturación MySQL — 18/10/2026

## Cambios autorizados

- Lab: conserva máximo activo 10 por pool y reduce conexiones ociosas a 2 con limpieza de 60 segundos. Política configurable y probada. No se sustituyen pantallas ni lógica operativa publicada.
- Staff: URL de Prisma con límite 8 por cliente para la conexión principal y 4 para una conexión de nóminas independiente si existe. No es un máximo agregado de toda la aplicación: se suma por proceso/cliente. Las credenciales y el destino se conservan. La tarea de sincronización tiene bloqueo de solapamiento y comienza 12 segundos después del minuto.
- Suite y Reservas: sin cambios en su configuración de conexiones. Ya tienen pools compartidos con límites por defecto 8/5, máximo ocioso 2 y limpieza de 60 segundos.
- Lab, importación de facturas: se mantiene cada cinco minutos, pasando del minuto 0/5/... al 2/7/... para separarla de Holded y otros trabajos.
- Web: monitor CLI cada minuto con lectura de contadores globales. Umbral al 80% (120 para límite 151), detección de nuevos rechazos y reinicios de contadores. Solo guarda estado y avisos locales fuera del webroot, sin correos ni WhatsApp nuevos. Dos archivos de avisos con rotación al superar 1 MiB. No expone SQL ni datos personales.

## Seguridad del despliegue

Copias privadas del runtime/configuración y crontab. El instalador de tareas cambia exclusivamente las dos tareas identificadas y añade un monitor. No imprime el contenido de crontab, variables ni secretos. Rechaza duplicados o formatos inesperados y permite comprobación sin escritura. No cambia horarios de otras tareas, destinatarios ni configuración de comunicaciones.

No se modifican esquemas, pedidos, reservas, nóminas ni documentos de clientes. No se ejecutan trabajadores de correo para probar. Las consultas de comprobación son SELECT/SHOW y se cierran sus conexiones.

La cuenta de operación no dispone de administración global de MySQL ni visibilidad de sesiones de otros usuarios. No se ha aumentado max_connections, cambiado permisos, cerrado sesiones ajenas ni reiniciado MySQL. Si el contador global sigue aumentando con las aplicaciones acotadas, es necesario que el administrador del servidor identifique las sesiones restantes y dimensione la capacidad.

## Validación

Lab: lint, sintaxis/typecheck, 22 pruebas de política/persistencia, equivalencia del ajuste compilado, build de producción y empaquetado standalone.

Staff: 22 pruebas de despliegue y sincronización, pruebas de URL/copias, sintaxis bash y prueba de bloqueo real con dos procesos simulados en Linux. La configuración no cambia la interfaz ni requiere recompilar Next.

Web: pruebas de umbral, variación de rechazos y reinicio, pruebas del instalador de cron, lint PHP y lectura de métricas con PDO real.

Tras publicar: salud y acceso de aplicaciones, presupuesto Prisma efectivo mediante consulta SELECT 1, contadores globales y conexiones de Lab transcurrido el tiempo de limpieza. Una muestra corta sin errores no garantiza ausencia de picos futuros; mantener los avisos y revisar su evolución.
