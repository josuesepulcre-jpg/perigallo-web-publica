# Analítica first-party de Perigallo

## Qué registra

La analítica se activa únicamente tras aceptar la opción de analítica en el aviso de cookies. Genera un `visitor_id` aleatorio en el navegador y un `session_id` por sesión, que caduca tras 30 minutos de inactividad.

Se registran de forma agregable: vistas de página, secciones visibles, hitos de scroll, clics marcados, fuentes UTM/referrer reducidas al dominio, dispositivo y los pasos de checkout. No se registra IP, user-agent completo, nombre, email, teléfono, contenido de formularios ni datos de pago.

Las compras e ingresos se consultan directamente de `ticket_orders` con estado `paid` y `is_test = 0`; no dependen de JavaScript.

## Migración

Desde el directorio activo de la web:

```bash
php -r 'require "api/src/bootstrap.php"; Perigallo\Ticketing\Database::pdo()->exec(file_get_contents("database/migrations/016_first_party_analytics.sql")); echo "Migración 016 aplicada.\n";'
```

## Cron de Plesk

Crear una tarea programada cada hora. El script solo envía informes cuando coincide con la hora configurada en `/admin/analitica/`, y guarda un registro idempotente por tipo y período.

```bash
cd /var/www/vhosts/perigallo.com/perigallo.com
php api/cron/analytics-report.php
```

Configuración recomendada: minuto `5` de cada hora. Para el informe diario a las 08:00 Europe/Madrid, configurar `08` en el panel y dejar activa la frecuencia diaria.

## Configuración

El destinatario, frecuencias y hora se guardan desde `/admin/analitica/` por la cuenta propietaria. Como alternativa inicial se admite `ANALYTICS_REPORT_EMAIL` en el entorno; la configuración del panel tiene prioridad.

## Verificación

1. Abre una página pública y acepta analítica.
2. Comprueba en DevTools que `POST /api/analytics/events` responde `202`.
3. Accede a `/admin/analitica/` y selecciona Hoy.
4. Configura el destinatario y usa “Enviar informe de prueba”.
5. Ejecuta el cron manualmente para comprobar que fuera de hora no duplica envíos.
