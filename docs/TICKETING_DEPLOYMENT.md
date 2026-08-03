# Despliegue del modulo de entradas

## Alcance

Este despliegue aplica solo a `perigallo.com`. No modifica Perigallo Suite ni Perigallo Reservas.

## Requisitos de servidor

- Apache o Nginx con PHP.
- PHP 8.1 o superior recomendado.
- Extensiones PHP: `pdo_mysql`, `openssl`, `json`, `mbstring`.
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
```

La segunda migración amplía eventos y entradas sin borrar pedidos, pagos, códigos ni asistentes ya existentes. Debe ejecutarse antes de desplegar la versión con editor.

Crear y editar un evento desde `/admin/entradas/` después de configurar usuario admin. El editor queda en `/admin/entradas/evento/?id=ID` y la vista previa privada en `/admin/entradas/vista-previa/?id=ID`.

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
DB_HOST=localhost
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
ADMIN_USERNAME=...
ADMIN_PASSWORD_HASH=...
REDSYS_ENV=test
REDSYS_MERCHANT_CODE=...
REDSYS_TERMINAL=1
REDSYS_CURRENCY=978
REDSYS_SECRET_KEY=...
MAIL_FROM=entradas@perigallo.com
MAIL_FROM_NAME=Perigallo
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
9. Escanear/verificar codigo en `/admin/entradas/acceso/`.

## Observaciones

- El sistema no integra Redsys en iframe.
- El TPV se abre como redireccion segura.
- La confirmacion de pago depende de la notificacion servidor a servidor.
- Si el email del servidor no esta configurado, los envios quedaran registrados como error en `email_deliveries`; el pedido/ticket no se pierde.
- La subida de imágenes se guarda en `assets/uploads/events/`. El proceso PHP debe tener permiso de escritura únicamente sobre esa carpeta; no se suben esos archivos al repositorio.
- En este alojamiento, confirmar siempre el `DOCROOT` real de Plesk antes de sincronizar archivos. El archivo `.env` debe existir solamente en el directorio servido y no debe entrar en Git.
