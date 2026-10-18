# Reducir conexiones — 18 de octubre de 2026

## Alcance y diagnóstico

El registro aportado muestra rechazo al abrir una conexión (MySQL/MariaDB 1040), antes de procesar la entrega. No identifica quién ocupa las conexiones ni demuestra que el pago haya fallado. Durante la publicación se ha accedido por SSH al servidor y se ha tomado diagnóstico de solo lectura. No se han cambiado límites, cerrado conexiones ni modificado pedidos o envíos.

El código local ya comparte una única instancia PDO por petición y el cron de entradas ya tiene bloqueo para impedir ejecuciones simultáneas. No se ha encontrado aquí un bucle de reconexión: los `attempt:1/2` del registro no aparecen en el cron local. Se ha confirmado que el cron desplegado tenía tres intentos de conexión separados por 0,5 y 1 segundo; esta entrega elimina solo ese bucle y conserva su diagnóstico, argumentos CLI y modo de solo lectura. Las protecciones de cola ya desplegadas se incorporan a main sin cambiar su comportamiento en producción. También se conserva el filtro de pedidos por evento que ya existía en producción.

## Cambios

- `api/index.php`: servicios creados solo al utilizarlos, después de los controles de ruta y acceso. Bots de analítica, lotes demasiado grandes, rutas inexistentes, verificación del webhook y métodos de pago ya no requieren conexión. No confundir las tres instancias de servicio con tres conexiones: antes ya compartían el singleton PDO, pero lo abrían innecesariamente.
- `api/src/PublicEventCache.php`: caché en el directorio privado de la suscripción (o temporal si no está disponible), exclusivamente de `GET /api/events`, 15 segundos por defecto. No se cachean detalles de eventos, disponibilidad, pedidos, precios de una compra ni validaciones de pago. Las compras siguen validándose en el servidor con sus transacciones existentes.
- Una sola renovación del listado con `flock`. Durante ella se permite reutilizar el listado anterior hasta 15 segundos adicionales (30 segundos de antigüedad máxima con la configuración por defecto). Sin copia reciente se espera como máximo un segundo; después se responde 503. Un disco no escribible omite la caché y conserva el comportamiento anterior. No se almacenan errores. La cabecera `X-Perigallo-Events-Cache` permite verificar HIT/MISS/STALE/BYPASS sin exponer información interna.
- `api/src/Database.php`: conexión no persistente explícita y espera de conexión de 5 segundos. No modifica el tiempo máximo de consultas ni reconecta/repite escrituras.
- Saturación 1040/1203: respuesta HTTP 503 con `Retry-After: 15` y explicación de que no se debe repetir un pago ya efectuado. Los demás errores de base de datos conservan un error HTTP 500. No se confirma ningún pago sin validación.
- `assets/js/analytics.js`: lotes cada 5 segundos en lugar de 1,4; mantiene el envío al completar 8 eventos, al ocultar la pestaña o salir. No genera pings de sesión mientras la pestaña está oculta y cancela temporizadores de lotes ya enviados. No añade reintentos de analítica.
- `api/cron/ticket-delivery.php`: conserva el bloqueo y el procesamiento de cola. Añade diagnóstico seguro de la fase y el código del driver; no incluye destinatarios, documentos, SQL ni secretos. Sin bucle de reconexión: cuando falla al conectar, la cola queda para la siguiente ejecución programada.
- `api/scripts/database-health.php`: diagnóstico CLI de solo lectura con límites, contadores agregados, cola y huella del cron. Los permisos del usuario MySQL pueden limitar la información visible. No muestra texto SQL activo ni información de clientes.

## Validación local

Primero se comprobó con PHP 8.4.25 en WebAssembly. Después se ejecutaron en un directorio privado de staging del servidor, con PHP nativo 8.4.26:

- 11 escenarios de API: conexión innecesaria ausente, listado servido desde caché, saturación simulada 503 en pedido y callback de Redsys.
- Ciclo de caché: 100 lecturas/una carga; expiración; fichero corrupto; desactivación; fallo de carga no almacenado y posterior recuperación.
- Clasificación de errores del driver; no confundir una clave duplicada con saturación.
- Pruebas JS de lotes: agrupación, pings suspendidos, envío al ocultar y ausencia de duplicación.
- Comprobaciones estáticas de ticketing, WhatsApp y contrato fiscal Holded.

El runtime WASM no reproduce los bloqueos de archivos nativos; esa limitación se ha resuelto ejecutando las pruebas de caché con PHP nativo en el servidor, donde sí pasan las comprobaciones de contención. Las pruebas de API usan una conexión simulada y no crean compras ni envíos. El cron actualizado se ha comprobado con `--dry-run` contra la instalación real: solo SELECTs, sin trabajos reclamados ni comunicaciones externas.

Comandos reproducibles:

```sh
php tests/database-pressure-test.php
PHP_BIN=php node tests/api-database-pressure.test.mjs
node tests/analytics-batching.test.mjs
node tests/static-ticketing-check.mjs
node tests/whatsapp-ticket-delivery-check.mjs
node tests/holded-fiscal-contract-check.mjs
```

Las comprobaciones de bloqueo pasan en PHP nativo. No se ha realizado una prueba de carga ni un pago real.

## Despliegue y comprobación en Plesk

1. Comparar el cron desplegado con el repositorio; conservar los cambios específicos del servidor si existen. No reemplazar a ciegas una versión distinta.
2. Obtener un informe antes de desplegar y otro durante actividad. En la ruta de instalación documentada:

```sh
/opt/plesk/php/8.4/bin/php /var/www/vhosts/perigallo.com/perigallo.com/api/scripts/database-health.php
```

3. Revisar `Threads_connected`, `Threads_running`, `Max_used_connections`, `Connection_errors_max_connections`, `max_connections` y `max_user_connections`. `Connections` es acumulativo, no conexiones simultáneas; `Max_used_connections` es histórico. Comparar diferencias entre informes. Las conexiones visibles pueden corresponder solo al usuario de la aplicación.
4. Comprobar una única tarea de entrega por minuto y que otros cron/pools de Suite, WordPress u otras aplicaciones no agoten el mismo servidor. El cron de este repositorio ya impide el solapamiento en un mismo sistema de archivos.
5. Desplegar juntos `api/index.php`, `api/src/PublicEventCache.php`, `api/src/Database.php`, `api/cron/ticket-delivery.php`, `api/scripts/database-health.php` y `assets/js/analytics.js`. No hace falta migración SQL. Las rutas `/api/scripts/` deben mantener las restricciones de acceso existentes; el nuevo diagnóstico también rechaza ejecución HTTP.
6. `PUBLIC_EVENTS_CACHE_SECONDS=15` es opcional; funciona por defecto sin editar `.env`. Para desactivar solo la caché, establecerlo a `0`. No copiar `.env.example` sobre `.env`.
7. Probar listado, detalle, formulario, checkout sandbox, callback firmado y entrega en entorno de pruebas; en producción comprobar solo pedidos pagados afectados y entregas pendientes antes de reenviar. No repetir cobros ni marcar trabajos como enviados sin comprobarlos.
8. Vigilar contadores y cola. Dimensionar `max_connections`/PHP-FPM solo con memoria y uso medidos; estas mejoras no eliminan una saturación causada por otra aplicación o una consulta lenta ajena a los cambios.

Reversión: restaurar el conjunto anterior de archivos o desactivar primero la caché. No se ha modificado ningún esquema ni dato de pedidos.

## Fuentes técnicas

- PHP, ciclo de vida de PDO: https://www.php.net/manual/en/pdo.connections.php
- MySQL, límite de conexiones: https://dev.mysql.com/doc/refman/8.4/en/too-many-connections.html

## Valoración técnica provisional: 5/10 → 8/10

Ámbito: gestión de carga y conexiones de la API; no es una nota de diseño, SEO ni una medición Lighthouse.

| Criterio (máximo 2 puntos) | Antes | Después |
| --- | ---: | ---: |
| Evitar conexiones innecesarias | 1 | 2 |
| Reutilizar lecturas públicas repetidas | 0,5 | 1,5 |
| Frecuencia y actividad de analítica | 1 | 1,5 |
| Protección de compras, colas y reintentos | 1,5 | 1,5 |
| Diagnóstico de saturación y capacidad | 1 | 1,5 |
| **Total** | **5** | **8** |

Es una valoración profesional basada en código y pruebas, no una reducción medida del 60% del consumo. Para mejorar la nota faltan observación bajo tráfico representativo, análisis del resto de aplicaciones que comparten MySQL y dimensionado de los pools y límites con medidas reales.

Diagnóstico previo al despliegue: `max_connections=151`; pico histórico `Max_used_connections=152`; `Connection_errors_max_connections=2831`. Este último contador es acumulativo y no equivale a 2831 clientes o compras. En la captura inicial había 23 conexiones y una consulta activa. El usuario de ticketing no tiene visibilidad de las sesiones de otras cuentas: no permite identificar quién provocó el pico.

La cola ya presentaba 7 trabajos email fallidos antes de esta publicación. La inspección no los reenvía ni los marca como resueltos. Requieren revisión separada del pedido, los registros y el proveedor antes de cualquier reenvío.
