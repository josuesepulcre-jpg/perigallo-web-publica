# Preparación de despliegue y restauración

Este procedimiento es una preparación; no se ha ejecutado sobre producción.

1. Resolver falta de espacio sin retirar originales hasta contar con copia verificada y aprobación concreta.
2. Desplegar candidata en carpeta privada aislada, con entorno staging, base sintética, proveedor sandbox, envíos dirigidos a pruebas y noindex. Revisar primero en PHP 8.5 y luego Apache/Plesk. No reutilizar claves o base productivas.
3. Ejecutar las pruebas pendientes de REVISION.md. Obtener aceptación visual y funcional.
4. Generar un paquete de rutas nuevas/modificadas desde la rama candidata. Excluir .env, uploads, storage, SQL, pruebas, scripts internos, documentación, historiales, copias y archivos de trabajo. Conservar archivos de usuarios y configuración actuales. El paquete temporal candidate-staging.tgz no es un paquete de lanzamiento.
5. Antes de publicar: registrar hashes de los archivos afectados, guardar sus versiones anteriores y la base en almacenamiento privado con espacio suficiente. Comprobar integridad y restaurar el backup en base aislada. No se requieren migraciones del esquema para esta candidata.
6. Solicitar aprobación expresa del usuario para la candidata concreta, la ventana y el dominio perigallo.com. Comprobar que producción no ha cambiado desde la auditoría y preservar nuevas correcciones operativas.
7. Publicar únicamente las rutas aprobadas mediante proceso compatible con Plesk, validar permisos y cachés. No usar despliegue automático desde main hasta reconciliar Git con la fuente real de servidor.
8. Verificar home, agenda API, ficha, checkout, sesión de administración y solicitudes; observar logs sin exponer datos personales ni secretos.

Rollback: restaurar exclusivamente los archivos sustituidos desde la copia anterior verificada y purgar caché/opcache según el proceso real de Plesk. Como no hay cambio de esquema, conservar la base actual, los pedidos y entradas generados durante la ventana. Nunca restaurar toda la base sobre ventas nuevas como reacción a un problema visual. Mantener pagos y webhooks de la versión operativa durante la vuelta atrás.

La restauración de datos se reserva a un incidente que la requiera, con procedimiento separado, aprobación y conciliación de transacciones. No se ha probado una restauración productiva en esta tarea.
