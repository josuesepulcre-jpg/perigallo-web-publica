# Control de acceso QR

## Modelo de seguridad

El QR conserva el token seguro ya emitido por el sistema. No contiene datos personales y no se acepta por su formato: el servidor resuelve el token, bloquea la fila de la entrada y comprueba evento, validez administrativa y presencia antes de registrar cualquier operación.

La validez administrativa permanece en `tickets.status` (`issued`, `cancelled`, `refunded`, `blocked`). La presencia física se guarda por separado en `tickets.access_status`:

- `not_entered`: la entrada todavía no ha accedido.
- `inside`: la persona se encuentra dentro.
- `outside`: la persona ha salido y puede reentrar solo si el evento lo permite.

`ticket_access_movements` es el historial auditable de entrada, salida, reentrada y corrección. Las correcciones no eliminan movimientos: crean una reversión vinculada al movimiento corregido.

## Uso operativo

1. Abrir `https://perigallo.com/check-in/` desde un móvil con HTTPS.
2. Iniciar sesión con un usuario `control_acceso` o `admin`.
3. Elegir evento y modo: **Automático**, **Solo entradas** o **Solo salidas**.
4. Escanear el QR. `POST /api/admin/tickets/access-preview` solo consulta la entrada y abre una confirmación; no crea movimientos ni modifica contadores.
5. Confirmar el movimiento mostrado. Solo `POST /api/admin/tickets/access-movement` registra la operación. Para primera entrada, salida y reentrada se exige el mismo gesto de confirmación.
6. Consultar las listas de Sin acceder, Dentro, Fuera e Historial desde la misma pantalla.

No se guardan movimientos sin conexión. Si dos dispositivos intentan operar sobre el mismo QR a la vez, la actualización condicional del servidor permite que solo uno complete el cambio; el otro debe volver a consultar la entrada.

## Configuración por evento

En el editor, pestaña **Fecha y horario**, configurar:

- Permitir reentrada.
- Máximo de reentradas (`0` o vacío significa sin límite).
- Fecha y hora límite de reentrada.
- Confirmación manual de reentrada.

La primera entrada siempre es válida para una entrada administrativa activa. Una entrada cancelada, reembolsada o bloqueada nunca obtiene acceso.

## Despliegue

Antes de copiar el PHP y JavaScript nuevos, ejecutar:

```bash
mysql -u DB_USER -p DB_NAME < database/migrations/009_ticket_access_movements.sql
```

Después realizar el despliegue normal y validar PHP en servidor. No modificar ni rotar `TICKET_QR_ENCRYPTION_KEY`: los QR ya emitidos dependen de ella.
