# Auditoría previa de optimización pública

Fecha: 4 de agosto de 2026. Alcance: repositorio público `perigallo.com`.

## Arquitectura detectada

- Sitio estático HTML, CSS y JavaScript sin framework ni proceso de build (`package.json` y `composer.json` no existen).
- API PHP propia en `/api/` para ticketing, administración, RedSys/Bizum, QR y correos. No se modifica el flujo de pago en esta intervención.
- Rutas públicas estáticas y detalle de eventos mediante regla Apache `/eventos/{slug}/`.
- La home concentra navegación, servicios, reservas y contacto. Las antiguas rutas de boda, celebración, finca y contacto eran páginas puente con `meta refresh`.
- Estilos: home con CSS inline; ticketing en hojas dedicadas. Se añade una hoja común sólo para las páginas públicas nuevas, sin alterar ticketing.
- Imágenes propias disponibles: tres hero de Perigallo, Finca La Llaguna y el logo. No se han inventado testimonios, cifras, premios o material adicional.

## Hallazgos y decisiones

- Separamos dos recorridos: celebraciones con propuesta y experiencias con entradas.
- Se sustituyen rutas puente por landings indexables con H1, introducción, proceso, FAQ, CTA, metadatos y breadcrumbs.
- Se mantiene `/eventos/` como listado y compra real; `/experiencias/` es su puerta comercial equivalente.
- No existe analítica declarada ni banner de cookies activo; no se añade un proveedor ni se afirma su uso.
- `robots.txt` ya excluye `/api/` y `/admin/`; se amplían las exclusiones de checkout y páginas con tokens.
- Los formularios de celebraciones continúan en Perigallo Suite, que es el flujo operativo actual. La web explica el beneficio en lugar de posicionar la herramienta como producto.

## Pendientes externos

- Confirmar relación jurídica y nombre oficial entre Perigallo y Finca La Llaguna antes de ampliar afirmaciones.
- Aportar galería, testimonios verificables, datos de equipo y casos reales para publicarlos.
- Medir Lighthouse y Core Web Vitals sobre el servidor con caché, compresión y configuración de imágenes reales.
- Validar jurídicamente los textos de cookies y las páginas legales antes de cambiarlos.
