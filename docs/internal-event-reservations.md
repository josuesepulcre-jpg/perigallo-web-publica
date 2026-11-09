# Reservas internas de eventos

Desde **Eventos → Reservas** se abre `/admin/reservas/?event=ID`. Cada evento conserva su propio listado, aforo, búsqueda y filtros. Una nueva reserva recoge tipo/cantidad de entradas, nombre, apellidos, teléfono, email opcional, fecha límite y notas internas.

## Estados y aforo

- **Reserva interna**: pedido pendiente con estado comercial `draft`. Descuenta plazas del mismo aforo que el checkout; todavía no hay QR ni enlace de pago activo.
- **Enlace preparado**: la acción «Preparar enlace de pago» habilita el enlace seguro hacia Redsys. Se muestra un mensaje que el operador puede copiar o abrir en su WhatsApp. Prepararlo no acredita que el mensaje se haya enviado. La reserva continúa descontando plazas.
- **Confirmada**: Redsys acepta el pago, o administración confirma explícitamente una entrada sin importe, un cobro ya recibido en efectivo o un pago pendiente en puerta. En este último caso se emiten las entradas, pero el cobro sigue pendiente y se registra por separado.
- **Cancelada / caducada**: libera las plazas pendientes. La fecha por defecto es el día siguiente al final del evento y se puede ajustar al crearla.
- **Cobro para revisar**: un callback válido llega después de liberar el aforo o después de cambiar la forma de cobro. Se conserva el registro del banco sin emitir plazas adicionales. Las plazas que ya estaban confirmadas siguen comprometidas. Una denegación posterior no revoca un cobro recibido, no reactiva una cancelación ni deshace una confirmación posterior en puerta.

La barra de aforo de Eventos suma confirmadas y reservadas. Adultos e infantiles comparten el límite del evento, además del límite de su propio tipo de entrada. El bloqueo se toma antes de la primera lectura con snapshot para no reutilizar un inventario antiguo después de esperar por otra transacción.

El guardado usa una clave de operación única y un hash del contenido. Un reintento devuelve la reserva ya persistida; reutilizar una clave con otros datos se rechaza. El evento se bloquea transaccionalmente antes de comprobar las plazas. Abrir de nuevo un pago interno en curso reutiliza el intento existente.

## API

Todas las rutas son de administración; los cambios exigen CSRF. Las respuestas llevan `Cache-Control: no-store`.

- `GET /api/admin/internal-reservations/meta`: eventos del selector.
- `GET /api/admin/events/ID/reservations?state=held&page=1&meta=1`: 20 reservas por página; `q` busca nombre, teléfono, email y referencia. `meta=1` incluye los tipos de entrada del evento.
- `POST /api/admin/events/ID/reservations`: guarda la reserva con `operation_key` e `items`.
- `GET /api/admin/internal-reservations/ID`: ficha y aforo canónicos.
- `PUT /api/admin/internal-reservations/ID`: corrige contacto y notas sin modificar plazas ni importes.
- `POST .../request-payment`, `.../confirm`, `.../cancel`: operaciones explícitas sobre la reserva.

Cada mutación devuelve la reserva confirmada por el servidor y el inventario del evento afectado. El navegador actualiza esas filas y contadores sin releer todos los eventos o pedidos. «Actualizar estado» permite consultar la respuesta del banco. El cobro en efectivo posterior a una confirmación en puerta reutiliza la ruta existente de pedidos.

## Activación

Requiere **032_internal_event_reservations.sql**, aditiva: cuatro columnas y dos índices en `ticket_orders`, sin modificar importes, ventas previas o tablas de producción.

```sh
php api/scripts/apply-migration.php database/migrations/032_internal_event_reservations.sql
```

La política de [despliegue de OVH](../ops/OVH-DEPLOY.md) exige revisión del operador para migraciones y pagos. El comando anterior es de activación, no una comprobación. Las pruebas se ejecutan únicamente en una base desechable; no se han aplicado migraciones ni efectuado operaciones en producción durante el desarrollo.

## Validación reproducible

No hay `package.json`, compilación ni typecheck en esta web PHP/JavaScript. Se comprueban sintaxis PHP y JavaScript, regresiones existentes e integración real con MariaDB.

```sh
node --test tests/*.test.mjs
node tests/static-ticketing-check.mjs
node tests/manual-ticket-share-check.mjs
php -l api/src/InternalReservationsTrait.php
php -l api/src/Ticketing.php
php -l api/index.php
RESERVATION_TEST_DSN='mysql:unix_socket=/tmp/perigallo-internal-reservations-db/mysql.sock;dbname=perigallo_reservation_test' php tests/internal-reservations-test.php
```

Para la integración, crear una base **local y desechable** llamada `perigallo_reservation_test` y cargar las migraciones en orden. El script rechaza otra base u otro servidor. No ejecuta trabajadores de correo, WhatsApp, facturación ni llamadas a Redsys; las notificaciones usan firmas y credenciales sintéticas.

La prueba de navegador `tests/internal-reservations-browser.cjs` espera un servidor PHP local con esa base, una sesión fixture disponible en `/fixtures/session`, y un JSON `RESERVATION_TEST_FIXTURE` con `event_id`, `adult_id`, `child_id`, `reservations`. Ese endpoint de sesión pertenece solo al router desechable de pruebas, no al producto. `PLAYWRIGHT_PACKAGE_PATH`, `RESERVATION_TEST_ORIGIN` y `RESERVATION_QA_OUTPUT` permiten usar el runtime y destino locales.

Resultados y límites en [el informe de rendimiento](performance/internal-event-reservations-20261109.md).
