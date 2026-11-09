# Reservas internas · Rendimiento y QA · 9 de noviembre de 2026

Entorno: PHP 8.5.12, MariaDB 12.3.3 por socket local y Chromium headless. Base desechable con 1.000 reservas sintéticas, dos tipos de entrada, límite global compartido y páginas de 20 filas. Navegador en Europe/Madrid. Las muestras adicionales de guardado se cancelan al terminar; las confirmaciones manuales pertenecen únicamente a fixtures. No se han ejecutado trabajadores de correo, WhatsApp, facturación ni peticiones a la pasarela.

## Mediciones

| Operación | Muestras | p50 | p95 |
| --- | ---: | ---: | ---: |
| Apertura del editor: respuesta visible | 20 | 33.6 ms | 35.8 ms |
| Guardado: respuesta visible al clic | 20 | 34.5 ms | 36.4 ms |
| Guardado: envío del formulario hasta respuesta durable | 20 | 16.3 ms | 17.5 ms |
| Actualización visual tras confirmar persistencia | 20 | 18.7 ms | 20.4 ms |
| Listado: HTTP + JSON | 20 | 6.9 ms | 7.4 ms |
| Guardar: HTTP + JSON después de commit | 20 | 13.7 ms | 15.0 ms |
| Cancelar: HTTP + JSON después de commit | 20 | 7.5 ms | 8.1 ms |

El feedback y la actualización visual se registran después de dos `requestAnimationFrame`. El guardado durable requiere la respuesta JSON del servidor tras su commit. Una prueba adicional retrasa esa respuesta: el formulario conserva «Guardando…» y bloquea el botón hasta recibirla.

Antes/después del cambio en las tarjetas de Eventos, 20 muestras de generación de HTML de 50 tarjetas sintéticas, solo CPU:

| Versión | p50 | p95 |
| --- | ---: | ---: |
| Base `06aea81` | 0.120 ms | 0.158 ms |
| Tarjetas con reservas y ocupación completa | 0.135 ms | 0.158 ms |

El flujo de reserva interna es nuevo; no había un guardado equivalente anterior. La comparación de tarjetas no mide navegación, red ni persistencia. Estos resultados locales cumplen el presupuesto de 100 ms en los tramos medidos, pero **no certifican producción** ni sus volúmenes, red, dispositivos o concurrencia reales.

## Peticiones y alcance

- Entrada a la pantalla: una sesión, un selector de eventos y un listado paginado del evento seleccionado.
- Guardar, preparar pago, confirmar y cancelar: una mutación que devuelve la reserva y el inventario canónicos del evento afectado. No se recarga la aplicación ni el catálogo completo.
- Cobrar después de confirmar en puerta: una petición al registro de efectivo existente y una lectura de la ficha afectada para obtener su estado canónico.
- Buscar y filtrar: una lectura paginada, con debounce de 220 ms para búsqueda e indicador inmediato. Cambiar de evento invalida respuestas anteriores.
- Índices nuevos: clave de operación única y `(internal_reservation_event_id, id)`.
- Lecturas de inventario y tipos limitadas al evento. La respuesta de un pedido puntual limita también su agregado al pedido afectado.

El JSON de evidencia registra las peticiones de la sesión completa. Errores de consola: 0; errores de red: 0; errores API en las mediciones: 0.

## Validaciones

- Integración MariaDB: reintentos simultáneos y secuenciales, límites del evento y del tipo de entrada, espera de locks bajo REPEATABLE READ, adultos/infantiles, cancelación, caducidad, efectivo, puerta y cero importe.
- Pago: callback con firma sintética, rechazo seguido de reintento, reapertura de un intento en curso, callback repetido, cobro fuera de plazo y notificaciones posteriores contradictorias. Una denegación repetida conserva la confirmación posterior en puerta, su aforo y sus entradas. Sin cobro ni mensajes reales.
- UI real: creación, feedback antes del commit, enlace/copia de mensaje, confirmación en puerta, cobro posterior, filtros, nombres largos y estado vacío.
- Permisos: cuenta de acceso sin administración rechazada; escritura sin CSRF rechazada con el código existente 419.
- Escritorio 1440 px, tablet 768 px y móvil 390 px; sin desbordamiento horizontal. Foco visible y diálogo navegable por teclado; cierre accesible durante el scroll.
- Regresión Node: 24 pruebas. Sintaxis de PHP/JS, estáticos de ticketing y WhatsApp, claves de Redsys, idempotencia de correo, documentos e inventario/caché.
- `npm run check:performance` del workspace Lab: aprobado al reutilizar sus dependencias instaladas. Sus presupuestos CPU son independientes de esta web PHP. Primer intento sin dependencias: faltaba `zod`; repetido con el directorio compartido existente, sin instalar paquetes.

No hay comando build/typecheck en este repositorio estático/PHP. Quedan pendientes la revisión y activación según la política de OVH, la migración 032 en producción y la medición de los mismos flujos en ese entorno.

## Evidencia

- `reports/internal-reservations/browser-qa.json`
- `reports/internal-reservations/event-card-before-after.json`
- Capturas `desktop.png`, `desktop-form.png`, `desktop-payment.png`, `tablet.png`, `tablet-form.png`, `mobile.png`, `mobile-form.png`, `mobile-empty.png`.
