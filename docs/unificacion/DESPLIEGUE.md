# Despliegue y rollback — Perigallo

La candidata está en https://revision-web.perigallo.com/ y en la rama codex/unificacion-web-20261108. No se ha publicado sobre perigallo.com. El manifiesto candidata-manifiesto.json identifica el commit, las rutas y hashes exactos del paquete.

## Estado completado

- Baseline real OVH conservado en b183590; no se presupone que main sea idéntico al servidor.
- Staging Plesk/Apache/PHP 8.5, HTTPS, base sintética independiente y Redsys test operativos.
- Pruebas documentadas en estado-unificacion.md; copia de la base productiva restaurada en base local aislada de 38 tablas.
- Copia de rollback de 64 archivos afectados comprobada en local por SHA-256; 30 rutas nuevas inventariadas. Sin deriva detectada en backend frente al baseline. Repetir comprobación al abrir la ventana.
- Paquete visual sin .env, SQL, uploads, almacenamiento, documentación, pruebas ni datos productivos. Incluye dos cambios exclusivamente de presentación en correo/PDF, además de archivos públicos y .htaccess.

## Ventana de publicación pendiente de autorización expresa

1. Obtener aceptación de esta candidata y aprobación para publicar en perigallo.com. No cambiar dominio/base/modo de pasarela como parte del despliegue visual.
2. Justo antes de ejecutar, revisar espacio, procesos y cambios producidos desde el baseline. Comparar hashes de todas las rutas afectadas; integrar cualquier corrección operativa nueva y repetir los controles que afecte.
3. Guardar copia privada verificable de cada archivo productivo que se sustituirá y listado de rutas nuevas. Renovar copia de base si ha pasado tiempo desde la verificada. Mantener secretos exclusivamente en el servidor.
4. Publicar solo las rutas del manifiesto aprobado, mediante preparación y sustitución controlada compatibles con Plesk. No extraer un árbol entero sobre datos ni activar despliegue automático desde main.
5. Conservar .env productivo, base, uploads, almacenamiento, cron, claves Redsys, configuración de Suite y servicios externos. No hay migración de esquema. El marcador y robots del staging no forman parte de la candidata de producción.
6. Validar permisos, caché y opcache; comprobar portada, agenda API, ficha, checkout, sesión administrativa y enlaces de solicitudes, observando registros sin divulgar datos privados. Cualquier cobro real de prueba requiere autorización específica.

## Rollback

Restaurar exclusivamente las versiones anteriores verificadas de las rutas sustituidas; retirar solo rutas nuevas inventariadas si corresponde, y renovar caché/opcache según Plesk. Conservar la base actual, los pedidos y las entradas generados durante la ventana. No restaurar una base antigua sobre ventas nuevas para resolver un problema visual.

Un incidente de datos exige procedimiento separado y conciliación de transacciones con autorización. La restauración probada fue en una base local aislada; no se ha ejecutado una restauración productiva.
