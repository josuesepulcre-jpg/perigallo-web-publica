# Checklist antes de produccion

## Antes de activar cobros reales

- [ ] Dominio `https://perigallo.com` con SSL valido.
- [ ] Base de datos aislada creada y con `001_ticketing_schema.sql` y `002_event_editor.sql` aplicadas, en ese orden.
- [ ] Variables de entorno configuradas fuera del repositorio.
- [ ] `APP_SECRET` generado con valor largo y unico.
- [ ] Usuario admin creado con `password_hash`.
- [ ] Redsys en TEST funcionando.
- [ ] Pago TEST correcto validado.
- [ ] Pago TEST rechazado validado.
- [ ] Notificacion Redsys recibida en `/api/redsys/notification`.
- [ ] URL OK no confirma pedidos por si sola.
- [ ] Tickets generados solo tras pago confirmado.
- [ ] Escaneo de acceso probado.
- [ ] Email de confirmacion probado o alternativa operativa definida.
- [ ] Stock probado con cupo bajo para evitar sobreventa.
- [ ] Crear, guardar y reabrir un evento en borrador desde `/admin/entradas/`.
- [ ] Crear, editar, reordenar y archivar un tipo de entrada desde el editor.
- [ ] Vista previa privada comprobada antes de publicar.
- [ ] Publicación, despublicación y publicación programada verificadas.
- [ ] Directorio `assets/uploads/events/` escribible por el usuario PHP/Plesk si se usarán subidas de imágenes.
- [ ] Politicas legales publicadas: privacidad, aviso legal, cookies, condiciones, cancelacion y reembolso.
- [ ] Cookies revisadas segun scripts reales cargados.
- [ ] Backups de archivos y base de datos antes del cambio.

## Cambio a produccion Redsys

Cambiar solo cuando el banco confirme datos reales:

```text
REDSYS_ENV=production
REDSYS_MERCHANT_CODE=...
REDSYS_SECRET_KEY=...
```

Mantener:

```text
REDSYS_PRODUCTION_URL=https://sis.redsys.es/sis/realizarPago
REDSYS_CURRENCY=978
REDSYS_TRANSACTION_TYPE=0
```

## Prueba posterior al cambio

- [ ] Crear evento de prueba con cupo minimo o importe controlado si procede.
- [ ] Realizar compra real supervisada.
- [ ] Confirmar notificacion.
- [ ] Confirmar pedido pagado.
- [ ] Confirmar tickets.
- [ ] Confirmar email.
- [ ] Confirmar escaneo.
- [ ] Revisar logs del servidor.

## Pendientes recomendados

- [ ] Generar QR real en imagen o PDF para cada ticket.
- [ ] Configurar SMTP transaccional dedicado.
- [ ] Export CSV de pedidos y asistentes.
- [ ] Busqueda avanzada por evento en admin.
- [ ] Integracion opcional futura con Perigallo Suite para CRM.
- [ ] CI con lint HTML/CSS/JS, revision de enlaces y control de archivos pesados.
