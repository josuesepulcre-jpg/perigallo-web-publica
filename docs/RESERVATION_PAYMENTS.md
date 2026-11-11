# Reservas manuales y cobros parciales

En Administración → Reservas y ventas, «Nueva reserva» crea una reserva sin pago. La ficha contiene cliente, evento, tarifa, plazas, total, cobrado, pendiente, pagos, enlaces y actividad. La pestaña Reservas y el filtro económico permiten localizarla. El estado de ocupación es independiente del estado del dinero.

Se reutilizan `ticket_orders`, `ticket_order_items`, el aforo y las entradas existentes. El nuevo canal `reservation` distingue estas fichas de la venta web, efectivo y enlaces manuales anteriores, que mantienen sus flujos. No se crea un inventario paralelo. Las reservas confirmadas asignan plazas aunque no se hayan cobrado; las temporales usan su fecha límite. El QR de las nuevas reservas se habilita al completar el total. Aumentar plazas deja el nuevo saldo pendiente y solo añade los QR que falten al completarlo; reducirlas cancela los sobrantes no utilizados. Se conservan pagos e historial.

## Cobros y enlaces

Registrar pago manual admite efectivo, tarjeta presencial, transferencia y otros métodos. Tarjeta presencial registra un cobro ya efectuado en un TPV externo; no ejecuta cargos. Fecha, referencia, notas y operador quedan registrados. Solo la cuenta propietaria puede modificar precios o registrar devoluciones. Una devolución exige confirmar que ya se ha realizado fuera de esta aplicación. Cancelar una reserva libera sus plazas y no devuelve dinero automáticamente.

Cada enlace permite solicitar parte o todo el saldo y se puede copiar para enviarlo manualmente. La página nueva `/entradas/pago-reserva/` prepara el mismo formulario firmado de Redsys existente. Abrirla o volver de la pasarela no registra dinero. El callback compartido verifica firma, versión, comercio, moneda, terminal, operación, entorno y el importe de ese intento antes de añadir el pago. Los datos de tarjetas no se guardan en el registro de la nueva notificación.

Los enlaces pendientes reservan parte de la deuda para evitar solicitudes solapadas; al cambiar el saldo manualmente o editar la reserva se cancelan los pendientes sin iniciar. Un intento ya iniciado retiene su importe y las plazas hasta conocer el resultado. Si se abandona la pasarela, la cuenta propietaria puede cerrar el intento únicamente tras comprobar en Redsys que no hubo cargo, con confirmación y motivo auditados. Una confirmación bancaria tardía inesperada siempre se conserva como dinero recibido y se señala para revisión; no se borra ni se convierte silenciosamente en otro cobro.

## Coherencia e integración

- Importes enteros en céntimos, transacciones InnoDB, bloqueo del evento para aforo y bloqueo de reserva para saldos.
- Referencias de operación únicas y huellas de contenido para crear reservas, registrar cobros/devoluciones y generar enlaces. Restricción única del intento bancario para no duplicar pagos.
- Revisión optimista para cambios concurrentes. Un fallo de auditoría revierte también el cobro, los cambios y los QR de esa operación.
- Los listados de caja incluyen el dinero parcial y los métodos mixtos; nunca sustituyen el cobrado por el total comprometido. Los listados de puerta muestran reservas pendientes y su deuda, sin generar un QR válido para ellas.
- Los indicadores de aforo separan reservas de entradas pagadas. Los indicadores económicos usan el neto de cobros menos devoluciones, incluido el dinero retenido tras una cancelación.
- Facturación utiliza el flujo existente al quedar pagada la reserva; se mantiene la exclusión establecida para ventas totalmente en efectivo. Si ya hay documento fiscal, un cambio de importe exige su rectificación. Las devoluciones de reservas facturadas se señalan para revisar la rectificación fiscal, sin ejecutar abonos remotos.
- Los endpoints antiguos de cancelación, devolución y corrección de contacto no pueden saltarse la ficha de los nuevos registros.

## Esquema y endpoints

Migración aditiva e idempotente: `database/migrations/032_reservation_payments.sql`. Añade revisión y referencia idempotente en pedidos, tablas `ticket_reservation_payments` y `ticket_reservation_links` y el vínculo nullable de intentos. Conserva todos los pedidos, estados y referencias existentes. Aplicar solo esta migración en producción, con el procedimiento habitual de respaldo y revisión del despliegue; no ejecutar las migraciones históricas de configuración.

- `GET /api/admin/reservations/meta`: catálogo existente y permiso de precios.
- `POST /api/admin/reservations`: creación sin pago.
- `GET/PUT /api/admin/reservations/{id}`: ficha / cambios.
- `POST /api/admin/reservations/{id}/payments`, `/refunds`, `/links`, `/cancel`.
- `POST /api/admin/reservations/{id}/links/{linkId}/cancel`.
- `GET /api/reservation-payment-links/{token}`, `POST .../{token}/start`.

Las rutas privadas reutilizan sesión, rol y CSRF. Solo la ruta pública con token aleatorio permite consultar el importe y preparar su pago; no expone teléfono o correo del cliente.

## Validación

`php tests/reservation-payments-test.php` utiliza una base aislada llamada `perigallo_reservation_qa` en localhost. No se ejecuta contra producción. Comprueba el ejemplo 520 € → 120 € efectivo → 200 € Redsys → 200 € TPV; firma, comercio, moneda, terminal, duplicados, exceso de pago, revisión concurrente, ediciones, devoluciones, cancelación, historial, informes, QR, conciliación tardía y aforo/cobros con procesos simultáneos. Inyecta un fallo de auditoría para verificar la reversión de toda la transacción. La integración se ejecuta en CI con MariaDB 10.11.

También se verifican las proyecciones económicas con Node y el flujo real en navegador en 1440, 768, 390 y 360 px. El POST de la pasarela se intercepta en la prueba del navegador; los callbacks se firman con una clave sintética local. No se hacen cargos reales, llamadas a proveedores ni envíos de mensajes. Se mantienen los controles de marca, enlaces públicos, Redsys, correo, WhatsApp y facturación existentes.
