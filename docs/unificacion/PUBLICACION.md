# Perigallo — publicación completada

La versión aprobada está publicada en https://perigallo.com/ . Commit desplegado: `a3e6f9b676de15c03e837b28e0c9d18acf370bd9`.

Se publicaron 95 archivos. La comprobación SHA-256 de los 95 coincide con el manifiesto. Se conservaron .env, robots, base de datos y lógica de pagos; cero migraciones de esquema y cero compras reales de prueba.

Validación posterior: portada con vídeo, nuevo lema, agenda conectada con el evento real, ficha Hora Cero y checkout de 120 € cargados correctamente; sin imágenes rotas ni errores de consola observados en el checkout. Doce comprobaciones HTTP de lectura aprobadas, incluidas redirecciones a Suite, acceso administrativo protegido y bloqueo de rutas internas PHP.

El primer intento se revirtió automáticamente al descubrir una diferencia entre el .htaccess de API de producción y staging. Se corrigió el bloqueo dentro del contexto de la API, se probó en staging y se publicó de nuevo con éxito. No cambió la lógica de negocio.

Rollback disponible: copia local y privada de 65 archivos anteriores verificada por SHA-256 y listado de 30 rutas nuevas. Hay copia inmediata de base de datos, además de la copia cuya restauración aislada de 38 tablas se verificó previamente.

Permanecen los límites documentados: WhatsApp sin prueba externa completa, Holded sin emisión fiscal real en esta tarea, devoluciones sin ejecutar abono financiero y disco al 99 % con aproximadamente 5,3 GB libres. No se han activado servicios externos nuevos.

Evidencias: publicacion-resultado.json, verificacion-produccion.json, candidata-manifiesto.json y captura perigallo-publicada.png. El paquete identificado en ese manifiesto queda conservado; no se regenera después de la publicación.
