# Preparación Holded (inactiva por defecto)

La integración se ha diseñado para que Holded sea el sistema fiscal maestro. La
web no genera numeración fiscal propia ni llama a Holded desde el retorno del
navegador.

## Estado seguro inicial

`HOLDED_ENABLED=false` y `HOLDED_DRY_RUN=true` son obligatorios hasta que se
complete la revisión fiscal y la activación expresa. La configuración de Redsys
se mantiene separada y en su entorno actual.

El callback firmado de Redsys solo encola un pedido real cobrado en producción.
`api/cron/holded-sync.php` procesa la cola fuera del callback con reintentos de
1 min, 5 min, 15 min, 1 h y 6 h. Los errores de permisos, configuración o una
sincronización interrumpida pasan a `requires_review`; no se reintenta a ciegas
para evitar duplicar documentos.

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
3. Ejecutar `php api/scripts/holded-health.php`: solo informa de configuración y
   recuentos; no hace peticiones a Holded ni escribe datos.
4. Validar con datos de prueba y una cuenta/entorno autorizado por Holded.
5. Revisar textos legales de facturación, conservación de datos y rectificativas
   con asesoría antes de cambiar `HOLDED_ENABLED`.
6. Aplicar `database/migrations/021_holded_invoice_delivery.sql` antes de activar
   el correo automático de facturas.

## Cron de facturación

El mismo cron procesa la cola fiscal y la entrega de facturas. Configurarlo en
Plesk cada 5 minutos, desde el directorio activo de la web:

```bash
cd /var/www/vhosts/perigallo.com/perigallo.com && php api/cron/holded-sync.php 20
```

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

- [Crear facturas](https://www.holded.com/es/desarrolladores/crear-factura)
- [Registrar pagos de facturas](https://www.holded.com/es/desarrolladores/registrar-pago-de-factura)
- [Crear recibos de venta](https://www.holded.com/es/desarrolladores/crear-recibo-de-venta)
- [Crear contactos](https://www.holded.com/es/desarrolladores/crear-contacto)
- [Crear notas rectificativas](https://www.holded.com/es/desarrolladores/crear-nota-de-venta)

La tabla `holded_refund_requests` prepara la trazabilidad de abonos/rectificativas;
no devuelve dinero ni emite notas automáticamente.
