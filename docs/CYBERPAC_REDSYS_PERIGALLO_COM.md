# Cyberpac Redsys en perigallo.com

## Objetivo

Perigallo.com usa Cyberpac/Redsys como pasarela de pago redirigida para entradas de experiencias pop-up. La tarjeta nunca se introduce en Perigallo.com: el usuario se redirige al entorno seguro de Redsys y Redsys devuelve el resultado al backend de Perigallo.

## Modo actual

El proyecto queda preparado para TEST.

```text
REDSYS_ENV=test
REDSYS_TEST_URL=https://sis-t.redsys.es:25443/sis/realizarPago
REDSYS_PRODUCTION_URL=https://sis.redsys.es/sis/realizarPago
```

No cambiar a produccion hasta completar el checklist de `docs/TICKETING_PRODUCTION_CHECKLIST.md`.

## URLs que debe validar el banco

URL de notificacion servidor a servidor:

```text
https://perigallo.com/api/redsys/notification
```

URL OK del navegador:

```text
https://perigallo.com/entradas/pago/correcto/
```

URL KO del navegador:

```text
https://perigallo.com/entradas/pago/error/
```

La confirmacion real del pedido solo se hace desde la notificacion servidor a servidor. La URL OK del navegador es informativa y no marca pedidos como pagados.

## Variables necesarias

Configurar en Plesk o en el entorno PHP, nunca dentro del repositorio:

```text
APP_ENV=production
APP_BASE_URL=https://perigallo.com
APP_TIMEZONE=Europe/Madrid
APP_SECRET=valor-largo-aleatorio

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=nombre_db_aislada
DB_USERNAME=usuario_db
DB_PASSWORD=password_db
DB_CHARSET=utf8mb4

ADMIN_USERNAME=usuario_admin
ADMIN_PASSWORD_HASH=hash_password_generado_con_password_hash

REDSYS_ENV=test
REDSYS_MERCHANT_CODE=codigo_comercio_test
REDSYS_TERMINAL=1
REDSYS_CURRENCY=978
REDSYS_SECRET_KEY=clave_secreta_test
REDSYS_SIGNATURE_VERSION=HMAC_SHA256_V1
REDSYS_TRANSACTION_TYPE=0
REDSYS_BIZUM_ENABLED=false
TICKET_RESERVATION_MINUTES=30

MAIL_FROM=entradas@perigallo.com
MAIL_FROM_NAME=Perigallo
```

## Bizum

Perigallo usa la misma integración **Redsys por redirección (Cyberpac)** para tarjeta y Bizum. No existe un segundo TPV ni se recogen teléfonos, PINes o claves Bizum en Perigallo: Redsys los solicita en su pasarela segura.

Mientras `REDSYS_BIZUM_ENABLED=false`, el checkout muestra Bizum con el estado **Próximamente**, pero no permite seleccionarlo ni iniciar un cobro. Cuando CaixaBank/Redsys confirme Bizum para el mismo FUC y terminal, activar:

```text
REDSYS_BIZUM_ENABLED=true
```

Bizum se firma en servidor como una autorización estándar y añade `DS_MERCHANT_PAYMETHODS=z` (minúscula). La tarjeta conserva los parámetros actuales. El callback firmado de `/api/redsys/notification` sigue siendo la única fuente que confirma el pedido y genera las entradas.

Antes de activar Bizum: aplicar `database/migrations/012_payment_methods_bizum.sql`, probar autorización, rechazo y abandono en TEST, y comprobar que el pedido figura como `Bizum` en `/admin/ventas/`. El logotipo oficial debe descargarse desde los materiales autorizados de Bizum/Redsys antes de incorporarlo como recurso visual.

## Flujo de pago

1. El cliente selecciona entradas en `/entradas/checkout/?event=slug`.
2. El backend crea un pedido pendiente y bloquea capacidad disponible de forma temporal.
3. El backend genera los parametros firmados de Redsys en servidor.
4. El navegador envia al cliente al TPV de Redsys.
5. Redsys llama a `/api/redsys/notification`.
6. El backend valida firma, importe, comercio, moneda, terminal y respuesta.
7. Si la respuesta es correcta, el pedido pasa a pagado, se generan tickets y se prepara email de confirmacion.
8. Redsys devuelve al navegador a una página de estado con el token público del pedido; esa página consulta el pedido hasta que llegue la notificación válida.
9. El usuario puede ver el pedido en `/entradas/pedido/?token=...`.

La vista previa privada del editor usa exactamente el mismo recorrido contra
Redsys TEST. El pedido queda marcado como prueba y no afecta al aforo ni a la
facturación de producción, pero el navegador sale de Perigallo para introducir
la tarjeta de pruebas en la pasarela real. No existen botones internos para
simular una aceptación o un rechazo.

## Seguridad

- La clave secreta de Redsys solo vive en servidor.
- No se aceptan pagos confirmados desde `UrlOK`.
- Las URLs de retorno solo contienen un token público del pedido para consultar su estado; el webhook firmado es la única fuente de confirmación.
- Cada notificacion Redsys se guarda en `payment_attempts`.
- El stock se calcula con pedidos pagados y reservas temporales no expiradas.
- La creacion de pedidos bloquea los tipos de entrada con `FOR UPDATE` para reducir riesgo de sobreventa.
- El admin usa sesion segura, password hash y CSRF.

## Paso a produccion

Solo cambiar:

```text
REDSYS_ENV=production
REDSYS_MERCHANT_CODE=codigo_real
REDSYS_SECRET_KEY=clave_real
```

Despues de:

- Validar pago test correcto.
- Validar pago test rechazado.
- Validar notificacion Redsys.
- Validar email de confirmacion.
- Validar escaneo de acceso.
- Hacer backup de base de datos.
