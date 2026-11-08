# Composición de experiencias en ordenador

Publicada el 8 de noviembre de 2026 en https://perigallo.com/experiencias/ y las dos historias del archivo.

El problema anterior separaba títulos y textos de imágenes situadas en una fila inferior, dejando grandes espacios vacíos. Desde 901 px, cada bloque presenta texto e imágenes en columnas: los capítulos individuales alternan sus lados y alinean verticalmente el relato con su imagen. Las galerías acompañan una columna de texto que permanece visible mientras se recorre la sección. Se limitaron el ancho del contenido y la altura de los vídeos verticales; las ilustraciones individuales conservan su proporción completa. Las tarjetas de la colección comparten tamaño y alineación superior.

La estructura agrupa los textos en archive-section-copy; hasta 900 px usa display:contents y conserva las reglas y el orden anteriores. No se modificaron contenido editorial, fotografías, vídeos, datos, ventas ni el relato inmersivo original.

Validación local y en staging antes de producción. PHP comprobado en Plesk. Revisión de Chrome a tamaño de ordenador, portátil de 1280 px y móvil de 390 px: sin desbordamiento. En producción, los 13 bloques móviles de La Perigalla conservan las mismas dimensiones anteriores. Visor de fotografías abierto y cerrado con retorno de foco; vídeo Comedy reproducido en staging sin error. Sin errores de consola observados. La hoja de estilos pública coincide por SHA-256 con la fuente.

Se sustituyeron tres archivos, con comparación previa contra la revisión Git 83bfeb4 y copia anterior verificable. Evidencias, manifiesto y copia local: outputs/experiencias-escritorio. Copia privada en OVH: /var/www/vhosts/perigallo.com/private/desktop-experiencias-20261108. Para revertir, comprobar cambios posteriores y restaurar solo los tres archivos del paquete anterior. No requiere cambios de base de datos.
