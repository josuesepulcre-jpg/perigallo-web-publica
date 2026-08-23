# Despliegue del modulo de entradas

## Alcance

Este despliegue aplica solo a `perigallo.com`. No modifica Perigallo Suite ni Perigallo Reservas.

## Requisitos de servidor

- Apache o Nginx con PHP.
- PHP 8.1 o superior recomendado.
- Extensiones PHP: `pdo_mysql`, `openssl`, `json`, `mbstring`; `curl` solo es necesaria para el envío opcional de WhatsApp mediante Meta Cloud API.
- MariaDB/MySQL.
- HTTPS activo.
- Variables de entorno configurables desde Plesk o archivo fuera del webroot.

## Archivos a subir

Subir desde una copia limpia:

```text
index.html
assets/
api/
admin/
eventos/
entradas/
database/migrations/
aviso-legal/
cookies/
condiciones/
politica-privacidad/
politica-cancelacion/
politica-reembolso/
bodas/
celebraciones/
pop-up/
reservar/
contacto/
la-finca/
favicon.svg
robots.txt
sitemap.xml
```

No subir:

```text
.env
.git/
.github/
.claude/
*.zip
*.bak
*.backup
*.old
*.orig
_reference-web-periallo/
node_modules/
docs/ si no se quiere exponer documentacion tecnica
```

## Base de datos

Crear una base de datos aislada para ticketing de Perigallo.com.

Aplicar migracion:

```bash
mysql -u DB_USER -p DB_NAME < database/migrations/001_ticketing_schema.sql
mysql -u DB_USER -p DB_NAME < database/migrations/002_event_editor.sql
mysql -u DB_USER -p DB_NAME < database/migrations/003_suite_experience_integration.sql
mysql -u DB_USER -p DB_NAME < database/migrations/004_long_public_event_information.sql
mysql -u DB_USER -p DB_NAME < database/migrations/005_configure_la_perigalla_01_publication.sql
mysql -u DB_USER -p DB_NAME < database/migrations/006_test_checkout_sandbox.sql
mysql -u DB_USER -p DB_NAME < database/migrations/007_la_perigalla_total_white_dress_code.sql
mysql -u DB_USER -p DB_NAME < database/migrations/008_secure_ticket_delivery_and_qr.sql
mysql -u DB_USER -p DB_NAME < database/migrations/009_ticket_access_movements.sql
mysql -u DB_USER -p DB_NAME < database/migrations/010_order_access_recovery.sql
mysql -u DB_USER -p DB_NAME < database/migrations/011_admin_users_and_order_operations.sql
mysql -u DB_USER -p DB_NAME < database/migrations/012_payment_methods_bizum.sql
mysql -u DB_USER -p DB_NAME < database/migrations/013_discount_codes.sql
mysql -u DB_USER -p DB_NAME < database/migrations/014_reference_ticket_price.sql
mysql -u DB_USER -p DB_NAME < database/migrations/017_ticket_attendee_allergies.sql
mysql -u DB_USER -p DB_NAME < database/migrations/018_order_access_conditions.sql
mysql -u DB_USER -p DB_NAME < database/migrations/020_holded_fiscal_sync.sql
mysql -u DB_USER -p DB_NAME < database/migrations/021_holded_invoice_delivery.sql
mysql -u DB_USER -p DB_NAME < database/migrations/022_checkout_runtime_compatibility.sql
mysql -u DB_USER -p DB_NAME < database/migrations/023_ticket_order_tax_breakdown.sql
mysql -u DB_USER -p DB_NAME < database/migrations/024_admin_cash_ticket_orders.sql
mysql -u DB_USER -p DB_NAME < database/migrations/025_ticket_attendee_dietary_preferences.sql
mysql -u DB_USER -p DB_NAME < database/migrations/026_event_test_mode.sql
mysql -u DB_USER -p DB_NAME < database/migrations/027_whatsapp_document_delivery.sql
mysql -u DB_USER -p DB_NAME < database/migrations/028_contacts_and_marketing_consents.sql
mysql -u DB_USER -p DB_NAME < database/migrations/029_manual_ticket_reserve_capacity.sql
mysql -u DB_USER -p DB_NAME < database/migrations/030_manual_ticket_inventory_mode.sql
```

La segunda migración amplía eventos y entradas sin borrar pedidos, pagos, códigos ni asistentes ya existentes. La tercera conserva esos datos y añade el identificador común para la integración privada con Suite. La cuarta cambia los textos públicos a `LONGTEXT`, sin eliminar contenido existente. Ejecutarlas antes de desplegar la versión con editor integrado. Para actualizar una instalación existente, ejecutar `008_secure_ticket_delivery_and_qr.sql` **antes** de copiar el PHP nuevo: añade las columnas y estados que este código consulta.

Crear y editar un evento desde `/admin/entradas/` después de configurar usuario admin. El editor queda en `/admin/entradas/evento/?id=ID` y la vista previa privada en `/admin/entradas/vista-previa/?id=ID`.

En una instalación existente, aplica las migraciones en orden antes del primer cobro. En particular, `025_ticket_attendee_dietary_preferences.sql` es necesaria para guardar dietas especiales sin bloquear el checkout.

## Password admin

Generar hash en un entorno seguro:

```bash
php -r 'echo password_hash("CAMBIAR_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

Configurar:

```text
ADMIN_USERNAME=...
ADMIN_PASSWORD_HASH=...
```

## Variables en Plesk

Configurar las variables de `.env.example` como variables de entorno PHP o en un archivo protegido fuera de `httpdocs`.

Valores minimos:

```text
APP_ENV=production
APP_BASE_URL=https://perigallo.com
APP_SECRET=...
# Generar con: openssl rand -base64 48. No reutilizar claves de Redsys.
TICKET_QR_ENCRYPTION_KEY=...
DB_HOST=localhost
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
ADMIN_USERNAME=...
ADMIN_PASSWORD_HASH=...
REDSYS_ENV=test
PAYMENT_ENVIRONMENT=sandbox
REDSYS_MERCHANT_CODE=...
REDSYS_TERMINAL=1
REDSYS_CURRENCY=978
REDSYS_TEST_SECRET_KEY=...
# Solo al activar Redsys REAL: usar una clave distinta de pruebas.
REDSYS_PRODUCTION_SECRET_KEY=...
REDSYS_BIZUM_ENABLED=false
MAIL_FROM=entradas@perigallo.com
MAIL_FROM_NAME=Perigallo
# WhatsApp solo se activa con una plantilla de Meta aprobada. No se usa ni se marca
# como enviado hasta que el proveedor acepta la solicitud.
WHATSAPP_PROVIDER=meta_cloud
WHATSAPP_AUTO_SEND=false
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_ACCESS_TOKEN=...
WHATSAPP_TEMPLATE=...
WHATSAPP_TEMPLATE_LANGUAGE=es
```

## Comandos de validacion

Desde el servidor:

```bash
php -l api/index.php
find api/src -name '*.php' -print -exec php -l {} \;
```

Comprobar rutas:

```bash
curl -I https://perigallo.com/
curl -I https://perigallo.com/eventos/
curl -I https://perigallo.com/entradas/checkout/
curl -I https://perigallo.com/admin/entradas/
curl -I 'https://perigallo.com/admin/entradas/evento/?id=1'
curl -I https://perigallo.com/api/events
```

## Prueba funcional en TEST

1. Crear un evento; se abrirá como borrador en el editor.
2. Guardar fecha, ubicación, contenido y al menos un tipo de entrada con precio y cupo.
3. Abrir **Vista previa** antes de publicar.
4. Publicar y comprobar la URL pública en `/eventos/SLUG/`.
5. Comprar una entrada con Redsys TEST.
6. Confirmar que Redsys llama a:

```text
https://perigallo.com/api/redsys/notification
```

7. Verificar pedido pagado en admin.
8. Abrir `/entradas/pedido/?token=...`.
9. Descargar una entrada y el pedido completo en PDF; ambos documentos deben abrir como PDF real.
10. Abrir el control móvil en `/check-in/`, iniciar sesión y escanear el QR de la entrada.
11. Confirmar una primera entrada, una salida y una reentrada. El mismo QR identifica la entrada durante todo el evento y cada movimiento se confirma contra el servidor.
12. Como administrador, verificar que solo se puede revertir el último movimiento y que la corrección aparece en el historial.
13. Ejecutar `010_order_access_recovery.sql`, abrir `/mis-entradas/` y solicitar un enlace con un pedido de prueba. La respuesta debe ser neutra tanto si existe como si no existe una compra; el enlace recibido debe abrir las entradas y caducar a los 30 días.
14. Ejecutar `011_admin_users_and_order_operations.sql`, entrar con la cuenta propietaria y abrir `/admin/usuarios/`. Crear una cuenta de prueba de control de acceso, comprobar que no puede entrar en ventas y después desactivarla.
15. En `/admin/ventas/`, comprobar los filtros. La cancelación revoca las entradas sin ejecutar un abono; **Registrar devolución** solo se usa después de devolver el importe desde el TPV/Redsys. La eliminación permanente solo se muestra para pedidos marcados como prueba y la cuenta propietaria.
16. Tras aplicar `012_payment_methods_bizum.sql`, comprobar que los pedidos muestran `Tarjeta` o `Bizum` en el backoffice.
17. Cuando CaixaBank/Redsys confirme Bizum para el mismo FUC y terminal, establecer `REDSYS_BIZUM_ENABLED=true` y probar una compra Bizum en TEST. El teléfono, PIN y autenticación se solicitan exclusivamente en la pasarela bancaria.
18. Tras aplicar `013_discount_codes.sql`, abrir `/admin/descuentos/`, crear un código de prueba y comprobar en checkout que el subtotal, descuento y total se recalculan. La API vuelve a validar el código justo antes de crear el pedido: el navegador nunca fija importes ni usos.

## Observaciones

- El sistema no integra Redsys en iframe.
- El TPV se abre como redireccion segura.
- La confirmacion de pago depende de la notificacion servidor a servidor.
- Si el email del servidor no esta configurado, los envios quedaran registrados como error en `email_deliveries`; el pedido/ticket no se pierde.
- WhatsApp se muestra como **no configurado** hasta que se conecte Meta Cloud API, se habilite `WHATSAPP_AUTO_SEND=true` y exista una plantilla aprobada. Nunca se marca como enviado sin una respuesta `2xx` de Meta. La plantilla debe respetar los opt-ins y reglas de WhatsApp.
- `TICKET_QR_ENCRYPTION_KEY` es obligatoria para emitir QR. Debe ser una clave privada larga, distinta de `REDSYS_SECRET_KEY`, y mantenerse fuera de Git. Una vez emitidas entradas reales, no se debe cambiar: protege los tokens QR ya emitidos.
- La migración `009_ticket_access_movements.sql` debe ejecutarse antes del PHP nuevo. Separa la validez administrativa de la entrada de su presencia física, registra movimientos auditables y migra validaciones antiguas como asistentes dentro del recinto.
- El acceso requiere HTTPS y una sesión de `admin` o `control_acceso`. El rol de control registra movimientos; solo el administrador puede revertir el último. Sin conexión no se registra ninguna operación.
- La cuenta definida mediante `ADMIN_USERNAME` y `ADMIN_PASSWORD_HASH` sigue siendo la **cuenta propietaria** y es la única que puede crear o desactivar usuarios y eliminar pedidos de prueba. Las cuentas creadas desde `/admin/usuarios/` se guardan con hash de contraseña en `ticket_admin_users`; no sustituye ni expone la cuenta definida en `.env`.
- Los códigos de descuento se gestionan exclusivamente desde `/admin/descuentos/`. Un código aplicado queda reservado mientras el pago está pendiente y solo se consume al confirmarse el pago. Las cancelaciones y devoluciones liberan su uso para mantener el historial y evitar que un pago fallido agote la campaña.
- El botón **Registrar devolución** no comunica con Redsys ni inicia un reembolso bancario. Registra una devolución que ya se ha procesado externamente y revoca los accesos correspondientes. Antes de usarlo con ventas reales debe existir un procedimiento financiero y de atención al cliente aprobado.
- El editor sube portada, tarjeta, imagen social, logotipo y galería a `assets/uploads/events/`; los vídeos promocionales se guardan en `assets/uploads/events/videos/`. El proceso PHP debe tener permiso de escritura únicamente sobre esas carpetas; los archivos subidos no se versionan en Git.
- Formatos de imagen admitidos: JPG, PNG, WebP y AVIF, hasta 5 MB. Formatos de vídeo admitidos: MP4, WebM y MOV, hasta 50 MB. SVG no se admite para evitar servir contenido vectorial no saneado.
- Para vídeos de más de 16 MB, ajustar en Plesk `upload_max_filesize` y `post_max_size` a al menos `64M` antes de probar la subida.
- Para condiciones legales o información pública muy extensa, ajustar `post_max_size` a al menos `16M`. El editor no limita caracteres; este valor debe ser mayor que el JSON completo enviado.
- En este alojamiento, confirmar siempre el `DOCROOT` real de Plesk antes de sincronizar archivos. El archivo `.env` debe existir solamente en el directorio servido y no debe entrar en Git.
