# Identidad Perigallo aprobada — 8 noviembre 2026

La fuente de la web incluye ahora la presentación publicada: símbolo de tres patas y wordmark Perigallo, Geist, carbón #141414, marfil #F3F3EE y verde #3C5142. Se conservan la portada, páginas públicas, navegación, agenda y archivo publicados, junto con sus recursos y rutas de soporte. Esta reconciliación evita desplegar de nuevo la presentación anterior desde main.

## Recursos canónicos

- Web: `media/identidad/perigallo-tres-patas.svg` y `media/identidad/perigallo-wordmark-white.svg`.
- Cabeceras de entradas y administración: `brand/perigallo-lockup-white.svg`.
- Correo y PDF: `brand/perigallo-lockup-white.png`. PNG permite mostrar el logo en clientes de correo sin soporte SVG.
- Fuentes PDF: `brand/Geist-Regular.ttf`.
- Iconos y `assets/images/perigallo-logo-original.png` son alias compatibles con la identidad nueva; el nombre del alias no implica que contenga el logo anterior.

## Entradas definitivas

`assets/js/ticket-pdf-layout.mjs` define la composición A5 de 148 × 210 mm que comparten el generador del servidor y la descarga del navegador. No crear otra composición independiente para uno de los canales.

`TicketDocumentService::DOCUMENT_STORAGE_VERSION` selecciona `v3-a5-20261108`. Al solicitar una entrada histórica, se obtiene la revisión vigente con los mismos códigos QR. `DOCUMENT_VERSION` mantiene `v2-attendee` para no invalidar la deduplicación de envíos. Los documentos y correos previamente emitidos se conservan. Las pruebas nunca envían mensajes reales.

## Comprobaciones antes de actualizar

El workflow `Brand and ticket regression` verifica los recursos aprobados, navegación y dependencias públicas, genera un PDF A5 con dos entradas sintéticas y prueba la selección del documento vigente de un pedido antiguo. Incluye pruebas de idempotencia, presión de base de datos y Redsys.

La modificación intencionada de los recursos de marca requiere actualizar `tests/fixtures/approved-brand-artwork.json` después de revisar su aspecto. Un cambio de composición requiere revisar también el versionado de documentos almacenados.

La página Hablemos pertenece a `perigallo-suite`. Su renovación ya está incorporada en main de ese repositorio (1e7a891 y sus descendientes). La web conserva los enlaces y redirecciones a `https://suite.perigallo.com/solicitud?origen=web-perigallo`.

Esta actualización guarda código y recursos. No cambia la política del controlador OVH, no ejecuta migraciones, no inicia cron ni colas, y no modifica pedidos, secretos o configuración de producción. El controlador mantiene su revisión de rutas protegidas y su detección de cambios manuales.
