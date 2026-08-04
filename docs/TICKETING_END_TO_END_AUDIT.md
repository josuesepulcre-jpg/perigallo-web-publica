# Auditoría del ciclo completo de entradas

Fecha: 4 de agosto de 2026
Ámbito: `perigallo.com` (módulo de experiencias, venta, entradas y acceso).

## Arquitectura actual

| Área | Implementación actual | Estado |
| --- | --- | --- |
| Frontend | HTML estático y JavaScript modular en `assets/js/ticketing.js` y `assets/js/ticketing-admin.js` | Activo |
| Backend | API PHP sin framework en `api/index.php` y servicios de dominio en `api/src/` | Activo |
| Persistencia | MariaDB mediante PDO y consultas parametrizadas (`api/src/Database.php`) | Activa |
| Eventos y entradas | `events`, `ticket_types`, `ticket_orders`, `ticket_order_items`, `tickets` | Activo |
| Pagos | Redsys con firma HMAC y notificación servidor a servidor | Activo; requiere monitorización y conciliación posterior |
| QR | Token aleatorio, hash en base de datos y cifrado AES-256-GCM del token recuperable | Activo |
| PDF | PDF individual/conjunto generado en navegador con jsPDF | Activo; no se persiste un PDF en servidor |
| Email | Registro en `email_deliveries` y envío actual por `mail()` | Activo, pendiente de SMTP transaccional verificable |
| WhatsApp | Adaptador Meta Cloud y registro en `ticket_delivery_logs` | Preparado; no se activa sin plantilla, opt-in y credenciales |
| Administración | Sesión propia con roles `admin` y `control_acceso` | Activo; faltan roles comerciales y de marketing |
| Acceso | Lectura QR/manual, confirmación humana, entrada, salida, reentrada y auditoría de movimientos | Activo |
| Suite | API privada con autenticación de servicio e idempotencia de experiencias | Activa para experiencias; no comparte directamente pedidos ni base de datos |

No existe framework frontend, ORM, worker, cola o programador de tareas. La aplicación no debe inventar esos servicios: las automatizaciones deben ejecutarse con tareas CLI/cron idempotentes hasta que se adopte una infraestructura de colas deliberadamente.

## Datos y relaciones reutilizables

```text
events
  └─ ticket_types
       └─ ticket_order_items
            └─ ticket_orders
                 └─ tickets
                      ├─ ticket_scans
                      └─ ticket_access_movements

ticket_orders
  ├─ payment_attempts
  ├─ email_deliveries
  └─ ticket_delivery_logs
```

- `ticket_orders.public_token` ya es un token aleatorio de acceso al pedido y no debe sustituirse ni exponerse en listados administrativos.
- Cada `tickets.public_code` identifica visualmente una entrada, mientras que el QR solo contiene un token aleatorio que el servidor resuelve y comprueba.
- `tickets.status` expresa el derecho administrativo; `access_status` expresa la presencia en el recinto. Esta separación ya está implementada y debe conservarse.
- `ticket_access_movements` es el historial inmutable de entrada, salida y reentrada. El aforo se calcula a partir de `access_status = inside`.

## Flujos que ya funcionan

1. El checkout crea una reserva y un intento de pago dentro de una transacción.
2. La notificación de Redsys valida firma, importe, moneda, comercio, terminal y tipo de operación.
3. El callback bloquea pedido e intento de pago, evita una segunda emisión y genera una entrada individual por unidad comprada.
4. Las entradas se muestran desde el enlace privado del pedido, se pueden descargar individualmente o en conjunto y tienen QR seguro.
5. El lector de acceso consulta primero y solo registra el movimiento tras confirmación humana; la actualización usa transacción y condición de estado para evitar carreras entre dispositivos.
6. Los intentos de email y WhatsApp quedan registrados. WhatsApp no se simula como entregado cuando no hay proveedor configurado.

## Gaps prioritarios

### P0: fiabilidad comercial y operativa

1. **No hay programador de tareas ni reintentos diferidos.** El envío se ejecuta tras la confirmación de pago; si el proceso termina después del pago, el pedido queda pagado, pero las entregas requieren intervención manual.
2. **No existe conciliación programada de pagos pendientes.** La notificación de Redsys es la fuente de verdad, pero no hay tarea que revise intentos pendientes/expirados.
3. **No hay recuperación segura de entradas.** Existe el enlace privado del pedido y reenvío desde él, pero no un flujo neutral por correo/teléfono con limitación de intentos.
4. **El correo usa `mail()` y no registra IDs ni webhooks de proveedor.** No permite confirmar entrega/apertura y depende de la configuración de Plesk.

### P1: producto de cliente

1. La página actual `/entradas/pedido/` cumple la función de “Mis entradas”, pero su URL y copy deben normalizarse sin invalidar enlaces ya emitidos.
2. No hay asignación de titular/contacto individual por entrada ni mecanismo de compartir una sola entrada.
3. Los PDF se generan en el navegador: son descargables y seguros, pero no hay archivo servidor ni reintento de generación en segundo plano.
4. Apple Wallet y Google Wallet no se pueden activar sin certificados/cuentas de emisor. No se deben mostrar botones hasta disponer de esas credenciales.

### P1: comunicaciones y postevento

1. Faltan plantillas HTML responsive de confirmación y plantillas aprobadas de WhatsApp con parámetros.
2. Faltan recordatorios, campañas por evento, exclusiones, bajas y reintentos controlados.
3. Faltan estado operativo de evento, valoraciones internas, enlace de reseña de Google, incidencias y panel de métricas.

### P2: administración y permisos

1. Solo hay `admin` y `control_acceso`. Faltan perfiles `gestor_evento`, `atencion_cliente` y `marketing` aplicados en backend.
2. Faltan la ficha operativa detallada del pedido, exportación y auditoría transversal de acciones administrativas.

## Riesgos y decisiones de diseño

- No se modificarán ni regenerarán QR existentes. Cualquier migración será aditiva y admitirá registros históricos.
- La confirmación de pago seguirá siendo exclusiva de la notificación firmada de Redsys; el retorno del navegador nunca marcará un pedido como pagado.
- Los trabajos de comunicación deberán ser idempotentes por pedido, canal y plantilla. Un fallo posterior al pago no puede revertir ni duplicar la emisión.
- Los clientes no verán estados internos de acceso ni errores de proveedores.
- WhatsApp y Wallet quedan como capacidades desactivadas hasta que existan consentimiento, plantilla/cuenta de Meta o certificados de emisor respectivamente.
- La integración con Suite se mantiene por API privada; no se mezclan bases de datos ni secretos.

## Orden de implementación aprobado

1. Base de datos y auditoría: estados de ciclo de vida, trabajos diferidos, enlaces de acceso/recovery y consentimientos sin alterar datos existentes.
2. Servicio CLI idempotente para conciliación, entrega y recordatorios, documentado para cron de Plesk.
3. “Mis entradas” y recuperación segura, manteniendo `/entradas/pedido/` como URL compatible.
4. Entregas premium: email HTML, reenvío administrativo y WhatsApp real cuando se configuren proveedor y opt-in.
5. Automatizaciones del evento, configuración y panel de comunicaciones.
6. Cierre de evento, valoración, reseñas de Google sin filtrado y alertas internas.
7. Wallet, analítica agregada y QA de regresión completo.

## Pruebas necesarias antes de producción comercial

- Notificación de Redsys aceptada, rechazada, repetida, firma inválida e importe inválido.
- Pedido con una y varias entradas, emisión única y regeneración de QR solo cuando no existía clave válida.
- Email/WhatsApp enviados, fallidos y reintentados sin duplicar mensajes.
- Enlace de pedido válido, caducado/revocado y recuperación neutral con rate limiting.
- Entrada, salida, reentrada, doble dispositivo y reversión.
- Chrome Android y Safari iOS: cámara, QR manual, teclado, safe areas y pantalla estrecha.
- Consentimientos separados, baja y exclusiones de comunicaciones no operativas.

## Credenciales externas necesarias

| Capacidad | Necesario para activarla |
| --- | --- |
| Email transaccional | SMTP/API de proveedor, remitente autenticado y dominio configurado |
| WhatsApp | Cuenta Meta Business, número, token, plantilla aprobada, webhook y opt-in |
| Apple Wallet | Cuenta de desarrollador Apple, certificado Pass Type ID y clave privada |
| Google Wallet | Cuenta Google Wallet issuer, service account y credenciales firmadas |
| Reseñas Google | URL oficial de reseña del establecimiento; no requiere API para mostrar el enlace |
