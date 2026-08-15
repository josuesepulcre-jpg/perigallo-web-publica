# Preparación Holded (inactiva por defecto)

La integración se ha diseñado para que Holded sea el sistema fiscal maestro. La
web no genera numeración fiscal propia ni llama a Holded desde el retorno del
navegador.

## Estado seguro inicial

`HOLDED_ENABLED=false` y `HOLDED_DRY_RUN=true` son obligatorios hasta que se
complete la revisión fiscal y la activación expresa. La configuración de Redsys
se mantiene separada y en su entorno actual.

El callback firmado de Redsys solo encola un pedido real cobrado en producción.
La decisión se toma a partir de `ticket_orders.environment` e `is_test`, nunca
del literal de entorno que devuelve Redsys (`real`/`test`). El cobro es la
fuente de verdad y la sincronización no depende del retorno del navegador.
`api/cron/holded-sync.php` procesa la cola fuera del callback con reintentos de
1 min, 5 min, 15 min, 1 h y 6 h. Los errores de permisos, configuración o una
sincronización interrumpida pasan a `requires_review`; no se reintenta a ciegas
para evitar duplicar documentos. El ID del documento se guarda inmediatamente
antes de registrar el pago; el siguiente intento reutiliza ese documento.

Cuando el comprador solicita factura, la sincronización crea el contacto y la
factura nominal en Holded. Con `HOLDED_AUTO_APPROVE=true` y
`HOLDED_AUTO_SEND_EMAIL=true`, el mismo cron envía al correo fiscal un enlace
privado para descargar el PDF; el comprador también podrá descargarlo desde su
pedido. Este enlace no guarda el PDF en la web ni expone la API Key de Holded.

## Configuración antes de activar

1. Confirmar con asesoría el impuesto, series y tratamiento de precios en
   Holded. La aplicación no deduce el impuesto fiscal desde la configuración de
   una entrada.
2. Completar las variables `HOLDED_*` en `.env` directamente en el servidor.
   Nunca guardarlas en Git ni en JavaScript público.
   `HOLDED_DEFAULT_TAX_RATE` debe coincidir con el impuesto seleccionado en
   `HOLDED_DEFAULT_TAX_ID` (por ejemplo, `10` con `s_iva_10`).
3. Ejecutar `php api/scripts/holded-health.php`: solo informa de configuración,
   recuentos por estado y diagnósticos seguros; no hace peticiones a Holded ni
   escribe datos. Antes de activar, debe indicar `enabled=true`,
   `dry_run=false`, `configured=true` y `missing=[]`.
4. Validar con datos de prueba y una cuenta/entorno autorizado por Holded.
5. Revisar textos legales de facturación, conservación de datos y rectificativas
   con asesoría antes de cambiar `HOLDED_ENABLED`.
6. Confirmar que están aplicadas, en este orden, las migraciones
   `020_holded_fiscal_sync.sql`, `021_holded_invoice_delivery.sql`,
   `022_checkout_runtime_compatibility.sql` y
   `023_ticket_order_tax_breakdown.sql` antes de activar el correo automático
   de facturas o recuperar ventas antiguas.

## Cron de facturación

El mismo cron procesa la cola fiscal y la entrega de facturas. Configurarlo en
Plesk cada 5 minutos como tarea **Ejecutar un comando**, desde el directorio
activo de la web:

```bash
cd /var/www/vhosts/perigallo.com/perigallo.com && /usr/bin/php api/cron/holded-sync.php 20
```

Si Plesk muestra otro binario de PHP, sustituir solo `/usr/bin/php`. La salida
es JSON seguro y, ante un fallo no controlado, contiene únicamente
`error_type`, `safe_code`, `http_status` y `order_id` cuando esté disponible.
Nunca incluye claves, cabeceras ni datos fiscales.

## Recuperar ventas reales ya cobradas

Primero ejecutar la previsualización, que no escribe nada:

```bash
cd /var/www/vhosts/perigallo.com/perigallo.com && /usr/bin/php api/scripts/holded-requeue.php --limit=100
```

Solo muestra pedidos reales, cobrados y de producción en `not_required`,
`pending` o `error`. No toca `synced`, `processing` ni `requires_review`. Si
la lista es correcta, aplicar la recuperación:

```bash
cd /var/www/vhosts/perigallo.com/perigallo.com && /usr/bin/php api/scripts/holded-requeue.php --limit=100 --apply
```

Los casos `requires_review` deben revisarse por la referencia Redsys en Holded
antes de pulsar **Preparar reintento** en el backoffice. Si no hay ID externo,
la interfaz exige confirmar que no existe documento ni pago remoto.

## Regla documental

Una solicitud de factura activa `invoice`. Sin solicitud, el límite por defecto
para un recibo simplificado es 400,00 € IVA incluido; por encima se prepara una
factura. Este umbral es `HOLDED_SIMPLIFIED_MAX_CENTS` y debe ser revisado por la
asesoría. Los recibos simplificados no crean contacto fiscal salvo necesidad
posterior; las facturas guardan un mapa local hash de NIF primero y correo
después para evitar contactos duplicados.

## Desglose de precios e impuestos

En el editor de entradas, el precio es siempre la **base imponible**. El IVA y
los gastos de gestión se calculan en Perigallo y se muestran al comprador
antes de pagar. El pedido conserva ese desglose; al sincronizar, Holded recibe
la base y el impuesto por separado, por lo que su total debe coincidir con el
cobro de Redsys.

La integración utiliza un único impuesto de Holded. Si una entrada tiene un
IVA distinto de `HOLDED_DEFAULT_TAX_RATE`, se detiene en revisión en lugar de
emitir un documento con un impuesto incorrecto.

## Endpoints oficiales utilizados

La integración usa la API v2 configurada en el proyecto
(`https://api.holded.com/api/v2`) con autenticación Bearer para facturas,
recibos de venta, contactos, pagos y PDF. No mezclar claves de la API v1 con
este flujo: Holded conserva v1 para integraciones antiguas y v2 es la API
actual. La documentación de la credencial generada en Holded debe confirmar el
acceso a esas operaciones antes de activar producción.

La tabla `holded_refund_requests` prepara la trazabilidad de abonos/rectificativas;
no devuelve dinero ni emite notas automáticamente.
