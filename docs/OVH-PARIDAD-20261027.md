# Paridad de OVH — 27/10/2026

Se reconcilian main y el sitio activo mediante una fusión de tres vías. Se conservan la navegación y marca presentes en main y los cambios operativos desplegados en OVH: presentación de Comedy, asistentes en entradas, enlaces manuales, validación de campos y protecciones de entrega.

Un resultado de correo ambiguo permanece pendiente de revisión. Solo un rechazo local inequívoco permite reclamar el reintento; los correos pendientes o aceptados no se repiten. La generación del documento no cambia QR existentes y conserva la exigencia de pago para generar el PDF. Las pruebas de idempotencia usan exclusivamente un transporte PHP sintético en el mismo namespace.

Publicar desde una lista de archivos aprobados y con copias recuperables; mantener .env, subidas, almacenes, pedidos y cron intactos. No ejecutar migraciones ni colas de correo/WhatsApp para comprobar la paridad. Las comprobaciones PHP y PDF se ejecutan sin red; las solicitudes en producción se limitan a lectura o rechazo temprano.
