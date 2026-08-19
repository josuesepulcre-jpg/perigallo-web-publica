# Entrega de entradas por WhatsApp Business

La entrega se inicia exclusivamente después de que el callback firmado de Redsys deja el pedido como pagado. El callback genera las entradas una sola vez y crea dos trabajos persistentes: email y WhatsApp. El cron `api/cron/ticket-delivery.php` procesa los trabajos; no se envía correo ni se llama a Meta dentro de la petición de Redsys.

Cada PDF se crea una vez por pedido y versión (`v1`), se almacena como documento protegido en base de datos y es el mismo adjunto para ambos canales. No hay URL pública permanente del PDF. Los QR se obtienen de los tokens cifrados ya asociados a cada entrada.

## Variables necesarias

No guardar valores reales en el repositorio ni en el panel de administración.

```dotenv
META_WABA_ID=
META_PHONE_NUMBER_ID=
META_ACCESS_TOKEN=
META_APP_SECRET=
META_WEBHOOK_VERIFY_TOKEN=
META_GRAPH_VERSION=v23.0
META_TEMPLATE_NAME=entradas_perigallo_confirmadas_v1
META_TEMPLATE_LANGUAGE=es_ES
TICKET_PDF_NODE_BINARY=node
```

También se aceptan los nombres anteriores `WHATSAPP_*` y los alias `META_GRAPH_API_VERSION` / `META_WHATSAPP_TEMPLATE*` cuando ya existan. `META_WABA_ID`, el identificador de número y el token se consultan en **Meta Business Suite → WhatsApp Manager → API setup**. El secreto y el token de verificación se gestionan en el panel de la app de Meta, en **Webhooks**.

Configurar el callback de Meta como:

```text
https://perigallo.com/api/whatsapp/webhook
```

Suscribir al menos el campo `messages`. La verificación `GET` usa `META_WEBHOOK_VERIFY_TOKEN`; las llamadas `POST` requieren la firma `X-Hub-Signature-256` calculada con `META_APP_SECRET`.

## Plantilla propuesta

Crear o presentar en WhatsApp Manager una plantilla de tipo `UTILITY`, idioma `es_ES`, con este payload de referencia. No se crea automáticamente sin credenciales y permisos explícitos.

```json
{
  "name": "entradas_perigallo_confirmadas_v1",
  "category": "UTILITY",
  "language": "es_ES",
  "components": [
    {"type": "HEADER", "format": "DOCUMENT", "example": {"header_handle": ["https://example.invalid/entradas-ejemplo.pdf"]}},
    {"type": "BODY", "text": "Hola {{1}}, tu compra para {{2}} está confirmada.\n\nNúmero de pedido: {{3}}\nEntradas: {{4}}\nFecha del evento: {{5}}\n\nAdjuntamos tus entradas en PDF. Cada entrada contiene su código QR de acceso. Guarda este documento y preséntalo al llegar al evento.\n\nTambién hemos enviado una copia al correo {{6}}.", "example": {"body_text": [["Ana Ejemplo", "La Perigalla 01", "PG-TEST-001", "2", "29/08/2026 19:00", "ana@example.test"]]}},
    {"type": "FOOTER", "text": "Perigallo · Entradas digitales"}
  ]
}
```

La aplicación consulta el estado de la plantilla antes de enviar. Si no está aprobada, los trabajos de WhatsApp quedan bloqueados y se reintentan; no se marcan como entregados ni se modifica el pago.

## Tareas de despliegue cuando se autorice

1. Aplicar `database/migrations/027_whatsapp_document_delivery.sql`.
2. Confirmar que Node está disponible para el usuario de Plesk (`node --version`) y que puede ejecutar el renderizador local.
3. Crear una tarea programada de Plesk cada minuto:

   ```bash
   /opt/plesk/php/8.4/bin/php /var/www/vhosts/perigallo.com/perigallo.com/api/cron/ticket-delivery.php 20
   ```

4. Crear/aprobar la plantilla con datos de ejemplo y configurar el webhook de Meta.
5. Probar únicamente con el número de prueba autorizado en Meta antes de activar compras reales.

El resultado de aceptar la API (`sent`) no se confunde con `delivered` o `read`: estos dos últimos se actualizan solamente con el webhook de Meta.
