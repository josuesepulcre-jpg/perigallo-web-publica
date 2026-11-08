# Archivo editorial de experiencias — revisión local y publicación

Proyecto: rama `codex/archivo-experiencias-20261108`. La revisión local documentada abajo se publicó posteriormente con aprobación expresa; ver PUBLICACION.md. Servidor local: http://127.0.0.1:4195 . Base aislada: `perigallo_archivo_local`, exclusivamente datos de prueba; sin correo saliente.

Actualización posterior: Comedy está publicada en producción con dos fotos y cuatro vídeos reales; ver COMEDY.md. El estado de borrador descrito abajo corresponde a la revisión local inicial.

## Páginas para revisar

- Colección y próxima experiencia: http://127.0.0.1:4195/experiencias/
- La Perigalla 01 — Noche ibicenca: http://127.0.0.1:4195/experiencias/vividas/la-perigalla-01-noche-ibicenca/
- Comedy, borrador privado: http://127.0.0.1:4195/experiencias/vividas/la-perigalla-comedy-ruben-serrano/?preview=1
- Editor, con sesión preparada en Chrome: http://127.0.0.1:4195/admin/experiencias/?id=2

La agenda local usa el contenido público de Hora Cero con inventario sintético y pasarela de prueba. No contiene pedidos ni datos de clientes de producción.

## Resultado

Colección editorial con composición amplia cuando hay una sola edición y cuadrícula asimétrica cuando crezca el archivo. Páginas renderizadas por PHP con metadatos propios, portada fotográfica o vídeo, historia, bloques independientes de momentos, gastronomía, destacados y vídeo; visor con navegación por teclado, Escape y devolución del foco. Vídeos locales con reproducción móvil, pausa de portada, suspensión fuera de vista y respeto de movimiento reducido. Enlaces YouTube/Vimeo sin cargar reproductores externos antes de la decisión del visitante.

El editor permite crear, editar y ordenar bloques y recursos; subir fotografías/vídeos, añadir enlaces de vídeo, escoger portadas, describir imágenes, destacar fotografías, cambiar formatos verticales, editar SEO, guardar, previsualizar, publicar, retirar, archivar y recuperar borradores. Las URLs se fijan al crear. Publicación y borrador son dos instantáneas: guardar una revisión no cambia lo visible hasta publicar. Control de versiones impide sobrescribir ediciones simultáneas. La asociación opcional con el evento de entradas impide que la automatización cree una segunda historia del mismo evento.

La Perigalla 01 reutiliza material existente. Se conservaron sin cambios 333 archivos del relato original y sus imágenes; su experiencia inmersiva completa sigue enlazada. Comedy incluye exclusivamente la fecha, artista y concepto facilitados por el usuario; queda en borrador con espacios de contenido preparados. No se atribuyen a esa noche fotografías o vídeos de otras ediciones.

Las imágenes nuevas se optimizan a WebP con lado máximo de 2000 px, conservando el original y corrigiendo orientación EXIF. Límite: 5 MB/16 MP por imagen y 50 MB por vídeo. Las secciones admiten hasta 40 recursos y las historias hasta 60 bloques. Los metadatos internos de la imagen optimizada se eliminan al recodificar.

## Pruebas ejecutadas

- 29 comprobaciones reales HTTP/PHP/MariaDB del archivo: autenticación, roles, CSRF, borradores, publicación, fecha, URL estable/duplicada, revisión concurrente, XSS, recursos inseguros, retirada/archivo/restauración, subida real de imagen y MP4, original conservado, WebP 2000 px, enlaces de vídeo y automatización idempotente sin publicación.
- 20 comprobaciones de regresión del sistema de entradas con callbacks firmados simulados: descuentos, importe calculado en servidor, firma/importe inválidos, emisión e idempotencia, QR, acceso duplicado, rechazo, cancelación y registro sintético de devolución. No son cobros ni abonos reales.
- Migración nueva aplicada dos veces; rollback y restauración de contenido verificados en una base temporal separada. No modifica tablas existentes.
- 333 archivos históricos y ocho módulos críticos de negocio comparados contra el commit de partida: sin cambios. 41 recursos referenciados comprobados por HTTP: todos disponibles.
- Chrome: colección, ambas historias y editor; visor, ordenación, guardado y previsualización desde el panel. Vídeo de prueba privado: reproducción efectiva y pausa comprobadas; retirado después de la comprobación.
- Ocho combinaciones de las cuatro vistas a 390×844 y 1024×768: sin desbordamiento horizontal ni imágenes rotas. El img sin src del visor cerrado se excluye del conteo de recursos rotos.
- Safari: primera historia, apertura del visor, siguiente imagen, Escape y devolución de foco aprobados. No se presenta como una auditoría exhaustiva de todos los navegadores.
- Preferencia de movimiento reducido revisada en CSS/JS; no se modificaron ajustes del sistema para simularla. Lint PHP/JS y comprobación de espacios del diff aprobados.

## Automatización y futura publicación

`api/cron/experience-archive.php` prepara una sola vez borradores de eventos terminados y no clasificados como pruebas. Usa ends_at; si falta, espera un día desde starts_at. No cambia el estado de venta ni publica historias. Puede ejecutarse también con el botón «Preparar eventos finalizados». El cron se ha probado, pero no se ha instalado en producción, porque esta entrega es exclusivamente local.

Para una futura publicación, pendiente de aprobación: guardar copia de fuente y base, aplicar `032_experience_archive.sql`, transferir solo los archivos revisados, importar las historias aprobadas asociándolas a los eventos existentes para evitar duplicados, y programar el cron con PHP de Plesk. Conservar .env, pedidos, entradas, uploads y servicios. Requiere extensiones PHP mbstring, intl, GD con WebP y fileinfo; límites PHP de subida adecuados. Validar de nuevo en Apache/Plesk, incluyendo rutas de detalle y acceso a borradores.

Rollback: restaurar los archivos anteriores y dejar los datos editoriales conservados. Si fuera necesario revertir el esquema, exportar y verificar primero experience_archive; el SQL de reversión está en scripts/archivo/032_rollback.sql. No restaurar una base antigua sobre ventas nuevas.

Para reiniciar la revisión local, con MariaDB local disponible y el .env local existente: ejecutar `scripts/archivo/start-local.sh` desde el proyecto. Las credenciales locales no se incluyen en Git ni en este informe.

Pendiente de contenido: fotografías y vídeos definitivos de Comedy, selección final de material de cada edición y aprobación editorial. Pendiente de entorno: validación representativa en Plesk antes de cualquier publicación. No se ha realizado ni solicitado todavía el despliegue de este desarrollo.

## Publicación posterior aprobada

Publicado el 8 de noviembre de 2026 en https://perigallo.com/experiencias/ . La Perigalla 01 está visible, Comedy permanece en borrador. El cron horario está instalado y comprobado. Ver PUBLICACION.md para verificaciones y rollback.
