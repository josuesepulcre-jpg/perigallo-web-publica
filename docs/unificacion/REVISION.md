# Perigallo — candidata unificada en staging

Revisión: 8 de noviembre de 2026. URL: https://revision-web.perigallo.com/ . Pendiente de aprobación para producción.

## Alcance y arquitectura

La estructura comercial procede de Perigallo Versión 2 (6744eba35c4e1f4deac4c04cc6936751c0267aa8). La base operativa es la fuente realmente publicada en OVH, preservada en b183590, incluidas correcciones que origin/main no contenía. Se integran 12 páginas comerciales y se conserva la operativa de 28 páginas administrativas, de venta y legales. El lema es «Escucha. Creación. Compromiso.».

La misma API PHP mantiene eventos, usuarios, roles, pedidos, descuentos, entradas y acceso. Las solicitudes siguen hacia Suite. La agenda consulta datos reales de la API, sin ventas simuladas en el navegador. No se requieren migraciones de esquema para publicar esta candidata. El staging tiene base propia y datos ficticios; producción sigue usando su base y configuración actuales.

Se comparte identidad, navegación y tipografía; se han adaptado editor, administración, venta, errores, correo y PDF de entrada. Los estilos funcionales necesarios para formularios, tablas y escáner se conservan. En backend solo cambian la presentación de TicketDeliveryQueue.php y render-ticket-pdf.mjs; la comprobación de huellas no detecta otros cambios. No se regeneran documentos ya emitidos.

## Pruebas realmente ejecutadas

| Área | Resultado y alcance |
|---|---|
| API y persistencia | 20 escenarios HTTP aprobados con MariaDB y PHP locales y con Apache/PHP 8.5 de OVH: sesión, CSRF, roles, crear/publicar evento, descuento 20 %, importe calculado por servidor, firmas e importes inválidos, emisión e idempotencia, QR, entrada duplicada, pago rechazado, cancelación y registro administrativo sintético de devolución |
| Tarjeta Redsys | Sandbox real del proveedor: pedido 261108171776, 25 €, aceptado, una entrada. Confirmación en base antes del regreso del navegador |
| Bizum | Sandbox real del proveedor: pedido 261108964916, 2 €, aceptado, una entrada. Confirmación en base antes del regreso del navegador |
| Rechazo en pasarela | Denegación de autenticación/cancelación, referencia 261108692215, SIS9915: pedido denegado, cero entradas, página de error coherente |
| PDF y QR | PDF generado por backend con nueva marca; QR decodificado desde el documento, previsualización, entrada, bloqueo del duplicado 409, salida y reentrada aprobados |
| Email | Una única entrada ficticia enviada a la dirección autorizada. Recepción confirmada en Gmail/INBOX y PDF adjunto de 26.906 bytes. No se hicieron envíos masivos |
| Agenda y API | 8 casos de fechas/filtros y 11 escenarios de presión aislados aprobados; prueba de claves Redsys aprobada con aviso PHP de import innecesario en el test |
| Idempotencia email | 6 escenarios sintéticos aprobados |
| Holded | 47 comprobaciones estáticas de contrato aprobadas y salud de configuración productiva consultada en lectura. No se emitieron documentos fiscales externos |
| Recursos | 48 páginas sin enlaces internos o recursos locales inexistentes. Recursos versionados por contenido para renovar caché |
| Administración | 10 destinos revisados en Chrome: panel, eventos, ventas, contactos, descuentos, facturación, usuarios, analítica, formulario y acceso; cabeceras y fuentes correctas, sin imágenes rotas ni alertas observadas |
| Responsive | 10 combinaciones de ruta/tamaño, tablet 1024×768 y móvil 390×844. Corregido desbordamiento de exportación en ventas y estilo del editor. Comprobaciones medidas sin desbordamiento horizontal en las rutas revisadas |
| Safari | Portada, vídeo, lema, despliegue del menú, aviso de privacidad renovado y carga de entradas/checkout verificados. No se repitió toda la compra en Safari |
| Infraestructura | DNS de staging, HTTPS con certificado válido, Apache/PHP 8.5, MySQL independiente, noindex y robots Disallow; rutas internas y .env responden 403, ruta inexistente 404 |
| Copias | Auditoría histórica: 528.733 archivos, cero diferencias SHA-256. Base productiva: copia nueva y restauración aislada de 38 tablas, sin aplicación conectada |

Evidencias detalladas en los JSON, capturas y resultados de outputs. Las pruebas simuladas no se presentan como operaciones externas reales. Las devoluciones se probaron como registro administrativo y bloqueo del QR, no como abono financiero del proveedor.

Documentación de sandbox utilizada: https://pagosonline.redsys.es/desarrolladores-inicio/integrate-con-nosotros/tarjetas-y-entornos-de-prueba/ y https://pagosonline.redsys.es/desarrolladores-inicio/documentacion-otros-metodos-de-pago/desarrolladores-bizum-1/ .

## Infraestructura y protección de datos

Producción: /var/www/vhosts/perigallo.com/perigallo.com, sin checkout Git. Staging: /var/www/vhosts/perigallo.com/revision-web.perigallo.com. Solo se añadió el registro DNS del subdominio de revisión. No se cambió el dominio principal, la base productiva ni el modo de pagos real.

El .env de producción no se descargó ni se incluye en el paquete. El staging usa únicamente clave test Redsys y base sintética; no dispone de credenciales reales de Holded o WhatsApp. El correo habitual del staging permanece desactivado; el único envío autorizado se ejecutó en un proceso controlado. No se programaron trabajadores o cron de staging.

Con autorización expresa y tras verificar copia local se retiraron únicamente suite-zod4-before, suite-security-before y suite-compat-before. La carpeta de auditoría completa y sus procesos activos se conservan. Tras montar staging quedan aproximadamente 5,3 GB libres; el disco sigue al 99 %.

## Incidencias y límites pendientes

| Gravedad | Situación | Consecuencia |
|---|---|---|
| Alta | Aprobación expresa de publicación pendiente | Bloquea el cambio definitivo a perigallo.com |
| Alta | Antes de la ventana: verificar deriva de archivos productivos y guardar copia de las rutas afectadas | Bloquea ejecutar el despliegue hasta completar ese control |
| Media | Disco con margen reducido (aprox. 5,3 GB/99 %) | Permite staging; requiere vigilancia de crecimiento y plan de capacidad |
| Media | WhatsApp sin token configurado en la inspección productiva; no probado extremo a extremo | No se certifica entrega WhatsApp; se conserva implementación existente |
| Media | Holded y devoluciones financieras sin operaciones externas reales | Configuración y contrato revisados; no se certifica emisión/abono externo nuevo |
| Media | Compatibilidad exploratoria Chrome/Safari, sin matriz exhaustiva de Firefox ni auditoría formal de accesibilidad/rendimiento | Aceptación visual del usuario y seguimiento tras lanzamiento |
| Baja | Avisos preexistentes si clientes omiten mode en escaneo o method en movimientos | Los clientes actuales incluyen ambos campos; lógica no alterada |

La candidata está disponible para revisión. El proyecto no se declara publicado ni finalizado en producción.
