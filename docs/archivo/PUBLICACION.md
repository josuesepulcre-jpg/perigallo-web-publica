# Archivo de experiencias — publicación completada

Publicado con aprobación expresa el 8 de noviembre de 2026 en https://perigallo.com/experiencias/ . Commit desplegado: `681d23e868813c809bb8085a9704df59735f3cf4`, rama `codex/archivo-experiencias-20261108` sincronizada con GitHub.

La Perigalla 01 está pública en https://perigallo.com/experiencias/vividas/la-perigalla-01-noche-ibicenca/ . Comedy también está publicada, con dos fotografías y cuatro vídeos recuperados de los chats del usuario: https://perigallo.com/experiencias/vividas/la-perigalla-comedy-ruben-serrano/ . Ver COMEDY.md para su publicación posterior y comprobaciones.

Actualización de diseño: composición de texto e imagen mejorada en ordenador; ver ESCRITORIO.md. Vista móvil conservada.

## Cambios aplicados

- 25 archivos de ejecución publicados y cotejados por SHA-256: cero diferencias frente al paquete aprobado.
- Migración aditiva 032 aplicada: una tabla editorial nueva, 39 tablas en total. No se migraron ni modificaron los estados de los eventos, pedidos, entradas o pagos existentes.
- Dos historias importadas y vinculadas a sus eventos originales: La Perigalla 01 → evento 1, publicada; Comedy → evento 3, inicialmente borrador y posteriormente publicada con material real.
- Cron horario instalado bajo el usuario perigallo, conservando las tareas existentes. Prepara borradores y nunca publica historias. Primera ejecución: cero borradores nuevos, sin duplicados.
- Configuración y secretos .env, robots, autenticación, reglas de venta y módulos críticos conservados. Los límites existentes de .user.ini ya permiten subidas de 64 MB y memoria de 256 MB; no se modificaron.

## Comprobaciones reales

Antes de publicar: 12 escenarios adicionales en staging OVH con Apache/PHP-FPM/MySQL, incluidos edición y guardado, publicación, snapshot público estable al guardar borradores, previsualización, bloqueo concurrente, CSRF, rol de puerta y subida real de imagen convertida a WebP de 2000 px. Se suman a las 29 pruebas locales del archivo, 20 regresiones de entradas y prueba aislada de rollback/restauración de esquema.

Después de publicar: rutas y API públicas correctas, 44 recursos estáticos comprobados por HTTPS, 18 fotografías disponibles en la historia, visor con siguiente/Escape/devolución del foco probado en Chrome, ausencia de errores de consola observados y cuatro combinaciones de colección/detalle en móvil 390 px y tablet 1024 px sin imágenes rotas ni desbordamiento. Canonical y metadatos individuales correctos; sin noindex en la historia pública. Los borradores y rutas internas están protegidos.

El panel de producción redirige correctamente al acceso privado cuando no hay sesión. Las pruebas de modificación y subida se realizaron en staging; no se crearon pedidos ni cobros reales de prueba en producción. Los estados originales de los tres eventos siguen siendo los mismos.

## Copias y rollback

Copia fresca de las 38 tablas previas, verificada y restaurada en la base local aislada `perigallo_restore_archivo_20261108`, sin aplicación conectada. Copia de los 15 archivos sustituidos verificada por SHA-256; diez rutas nuevas inventariadas. Copias locales en outputs/archivo-experiencias/publicacion y copia de fuente privada en OVH: /var/www/vhosts/perigallo.com/private/archive-release-681d23e-20261108.

Para volver atrás: retirar exclusivamente la nueva línea de cron de experience-archive.php, preservando cualquier tarea añadida después; restaurar los 15 archivos anteriores y retirar solo las diez rutas nuevas del manifiesto, tras comprobar que no hayan recibido cambios posteriores. Conservar la tabla editorial y las subidas. No restaurar una base antigua sobre ventas nuevas. La eliminación del esquema exige una exportación editorial verificada y una decisión separada.

Contenido de Comedy recuperado y publicado el 8 de noviembre; detalle en COMEDY.md. El disco continúa con margen reducido, aproximadamente 5,3 GB libres, situación preexistente documentada.
