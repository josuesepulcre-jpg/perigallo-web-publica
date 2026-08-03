# Perigallo.com

Web publica de Perigallo y modulo de venta de entradas para experiencias pop-up.

Este repositorio corresponde solo a `perigallo.com`. No es Perigallo Suite, no es `suite.perigallo.com`, no es `reservas.perigallo.com` y no contiene codigo de esas aplicaciones.

## Alcance

- Home publica y rutas comerciales de Perigallo.
- Enlaces externos oficiales a Perigallo Suite y Perigallo Reservas.
- Paginas publicas de eventos y checkout de entradas.
- API PHP propia para pedidos, tickets, notificacion Redsys y panel privado de gestión completa de eventos.
- Documentacion de despliegue para Plesk/Cyberpac Redsys.

## Integraciones externas

- Solicitud inicial: `https://suite.perigallo.com/solicitud?origen=web-perigallo`
- Reservas pop-up externas: `https://reservas.perigallo.com/reservar?source=web`
- Web de la finca: `https://fincalallaguna.com/`
- WhatsApp: `https://wa.me/34691499985`

Las solicitudes y reservas externas se abren fuera de la pagina principal para evitar iframes bloqueados o experiencias integradas deficientes.

## Rutas publicas

| Ruta | Funcion |
| --- | --- |
| `/` | Home publica principal. |
| `/eventos/` | Listado publico de experiencias con entradas. |
| `/eventos/{slug}/` | Detalle publico de un evento. |
| `/entradas/checkout/?event=slug` | Checkout de entradas. |
| `/entradas/pedido/?token=...` | Resumen de pedido y entradas emitidas. |
| `/entradas/pago/correcto/` | Retorno informativo de pago correcto. |
| `/entradas/pago/error/` | Retorno informativo de pago fallido/cancelado. |
| `/admin/entradas/` | Listado privado de eventos, ventas y entradas. |
| `/admin/entradas/evento/?id=ID` | Editor completo de evento y tipos de entrada. |
| `/admin/entradas/vista-previa/?id=ID` | Vista previa privada del evento, incluso en borrador. |
| `/admin/entradas/acceso/` | Escaner/verificacion privada de accesos. |
| `/solicitud-evento/` | Pasarela legacy hacia la solicitud oficial de Perigallo Suite. |
| `/politica-privacidad/` | Politica de privacidad. |
| `/aviso-legal/` | Aviso legal. |
| `/cookies/` | Politica de cookies. |
| `/condiciones/` | Condiciones de compra. |
| `/politica-cancelacion/` | Politica de cancelacion. |
| `/politica-reembolso/` | Politica de reembolso. |
| `/la-finca/`, `/bodas/`, `/celebraciones/`, `/pop-up/`, `/reservar/`, `/contacto/` | Rutas stub temporales hacia secciones o enlaces relacionados. |

## Backend

El backend esta en `/api` y requiere PHP con PDO MySQL/MariaDB y OpenSSL.

Endpoints principales:

- `GET /api/events`
- `GET /api/events/{slug}`
- `POST /api/orders`
- `GET /api/orders/{token}`
- `POST /api/redsys/notification`
- `GET /api/admin/session`
- `POST /api/admin/login`
- `POST /api/admin/logout`
- `GET /api/admin/summary`
- `GET /api/admin/orders`
- `GET /api/admin/events`
- `GET /api/admin/events/{id}`
- `POST /api/admin/events`
- `PUT /api/admin/events/{id}`
- `POST /api/admin/events/{id}/publish`, `/unpublish` y `/duplicate`
- `GET /api/admin/events/{id}/preview`
- `POST /api/admin/events/{id}/ticket-types`
- `PUT /api/admin/events/{id}/ticket-types/{ticketTypeId}`
- `POST /api/admin/media`
- `POST /api/admin/tickets/scan`

## Desarrollo local

Para revisar la parte estatica:

```bash
python3 -m http.server 8000
```

Abrir:

```text
http://127.0.0.1:8000/
```

Para probar `/api` hace falta un entorno PHP compatible con Apache/Nginx y MariaDB. Ver `docs/TICKETING_DEPLOYMENT.md`.

## Variables de entorno

Copiar `.env.example` a un entorno seguro fuera del repositorio o cargar las variables desde Plesk. No commitear `.env`.

Redsys debe empezar siempre en TEST:

```text
REDSYS_ENV=test
REDSYS_TEST_URL=https://sis-t.redsys.es:25443/sis/realizarPago
```

## Base de datos

Aplicar:

```bash
mysql -u USER -p DB_NAME < database/migrations/001_ticketing_schema.sql
mysql -u USER -p DB_NAME < database/migrations/002_event_editor.sql
```

La primera migración crea las tablas aisladas para eventos, tipos de entrada, pedidos, intentos de pago, tickets, escaneos y entregas de email. La segunda amplía los eventos y entradas sin alterar los pedidos o tickets emitidos.

## Validacion minima

```bash
node --check assets/js/ticketing.js
node --check assets/js/ticketing-admin.js
node --check assets/js/site.js
node tests/static-ticketing-check.mjs
```

Si hay PHP disponible:

```bash
php -l api/index.php
find api/src -name '*.php' -print -exec php -l {} \;
```

## No desplegar

- `.env` o variantes reales.
- Zips, backups, snapshots y referencias internas.
- `.claude/`, `.DS_Store`, logs, temporales.
- Dumps SQL/DB, certificados, claves privadas.
- Documentacion interna que no forme parte del paquete aprobado.

## Documentacion clave

- `docs/CYBERPAC_REDSYS_PERIGALLO_COM.md`
- `docs/TICKETING_DEPLOYMENT.md`
- `docs/TICKETING_PRODUCTION_CHECKLIST.md`
- `docs/estructura-deploy-web-publica.md`
