# Unificación Perigallo — candidata de desarrollo

Fecha: 8 de noviembre de 2026. No publicada en producción.

## Arquitectura y conservación

La fuente visual es «Perigallo · Versión 2», commit 6744eba35c4e1f4deac4c04cc6936751c0267aa8. La base operativa es el código descargado de la carpeta publicada de OVH, no solamente origin/main: el servidor contiene correcciones que GitHub todavía no reflejaba. Se conservan separadamente en el commit b183590.

Una única aplicación HTML/JS + API PHP existente. Una única base operativa de producción; no se propone migrar su esquema. El entorno local utiliza exclusivamente una base nueva con datos sintéticos. El lema aprobado es «Escucha. Creación. Compromiso.».

| Área | Integración |
|---|---|
| Inicio y 11 páginas comerciales | Estructura, contenido, fotos, vídeos, fuentes y navegación aprobados |
| Agenda | Adaptador de presentación para GET /api/events; precios y disponibilidad controlados por API |
| Ficha de evento y checkout | Se conservan contratos, validaciones, consentimientos y scripts operativos |
| Pagos | Redsys y Bizum configurables existentes, validación de notificación en servidor |
| QR y acceso | Generación, previsualización y movimientos existentes; roles conservados |
| Pedidos, clientes, facturación, descuentos | Pantallas y lógica actuales conservadas; identidad compartida |
| Solicitudes y reservas | Enlaces al servicio existente Suite; origen web-perigallo |
| Correos, WhatsApp y Holded | Implementación existente conservada; no enviados a terceros en pruebas |
| Avisos legales | Textos actuales conservados; cabecera, pie y tipografía unificados |

Los scripts de simulación del prototipo (enquiry y local-order-state) no se incorporan. No se sustituyen las transacciones del servidor por estado de navegador. Los estilos operativos necesarios para formularios, tablas y escáner se mantienen bajo una capa de identidad común; queda una revisión visual completa de todas las pantallas antes de calificarlas como definitivas.

## Evidencia ejecutada

- 32 archivos de migración aplicados en una base local vacía, aislada de producción.
- 20 comprobaciones HTTP con la API y MariaDB reales locales: sesión, autorización, CSRF, borrador, publicación, descuento del 20 %, precio calculado por servidor, formulario de pasarela test, rechazo de firma e importe inválidos, callback firmado simulado correcto, emisión de dos entradas, idempotencia, consulta QR, movimiento de entrada, rechazo de entrada repetida y restricción del rol de puerta, pago rechazado sin entradas, cancelación de pedido y bloqueo de entrada reembolsada. El reembolso probado es únicamente su registro administrativo local; no ejecuta un abono en el proveedor.
- No se contactó Redsys: los callbacks son un simulador firmado con una clave ficticia. No equivalen a homologación ni a compra real en la pasarela.
- Pruebas de agenda/admin: 8 escenarios de fechas y filtros aprobados; prueba de presión API: 11 escenarios aislados aprobados. Test de claves Redsys aprobado, con aviso PHP del archivo de prueba por un import innecesario. Idempotencia de correo: 6 escenarios sintéticos aprobados, cero mensajes reales.
- 48 páginas revisadas automáticamente: sin enlaces internos ni recursos estáticos inexistentes. No es una comprobación de destinos externos ni una garantía de su contenido.
- Huellas de API y migraciones coinciden con el baseline operativo descargado: cero cambios de backend durante la adaptación visual.
- Chrome: portada, login, panel, lista de eventos, ficha pública y primer paso del checkout; menú móvil 390 px y panel tablet 1024 px, sin desbordamiento horizontal medido. Pendiente revisión de todas las pantallas y navegadores.

## Infraestructura observada

Docroot real: /var/www/vhosts/perigallo.com/perigallo.com. No es un checkout Git. PHP 8.5.12. Acceso Plesk y SSH comprobado. No se descargó .env de producción. El staging local ejecuta PHP con envío de correo desactivado, sin claves de servicios externos y sin datos reales.

El intento de montar staging privado en OVH falló por falta de espacio. Se retiró exclusivamente la carpeta incompleta creada por esta tarea. Tras retirarla: volumen 457 GB, 434 GB usados, aproximadamente 54 MB disponibles, 100 %. Inventario preparado y copia local de la auditoría histórica terminada: 528.733 archivos comprobados por SHA-256, cero diferencias. Tres copias inactivas propuestas para liberar unos 5,7 GB, pendientes de aprobación concreta. No se ha retirado ninguna copia histórica.

## Incidencias y pruebas pendientes

| Gravedad | Pendiente | Bloqueo |
|---|---|---|
| Crítica | Disco OVH sin espacio | Staging, backups y despliegue bloqueados |
| Alta | Ejecutar staging representativo en OVH con PHP/Apache y configuración aislada | Bloquea publicación |
| Alta | Compra en sandbox del proveedor: tarjeta/Bizum, fallo y cancelación | Bloquea publicación |
| Alta | Entrega real de email y QR; WhatsApp/Holded con destinos de prueba y configuración revisada | Bloquea certificación de integraciones |
| Alta | Backup fresco de base de producción y restauración aislada verificadas | Bloquea publicación |
| Media | Revisión completa responsive de editor, pedidos, usuarios, descuentos, facturación y escáner | Bloquea aceptación visual definitiva |
| Media | SEO, caché, redirecciones bajo Apache, SSL y comparativa de navegadores | Pendiente |
| Baja | La API de escaneo avisa si falta mode; movimientos avisan si falta method | Incidencia preexistente: los clientes actuales envían ambos campos; documentada, no alterada por diseño |

No se declara terminado el proyecto. No hay aprobación de publicación ni cambio de dominio, base o pagos reales.
