# La Perigalla Comedy publicada

Publicada por indicación expresa del usuario el 8 de noviembre de 2026 en https://perigallo.com/experiencias/vividas/la-perigalla-comedy-ruben-serrano/ . Aparece también en la colección de Experiencias vividas, junto a La Perigalla 01. Conserva su asociación con el evento 3, sin modificar entradas, pedidos ni pagos.

## Material recuperado

El chat «Montar vídeo Instagram» (6aef78da-a6bc-83eb-aabb-485e41656603) contenía las dos fotografías de la sala y Rubén Serrano y los originales de los clips de monólogo, artista con público y música en directo. El chat «Editar vídeo profesional» (6aee1aec-74e4-83eb-a718-73fb02ecc852) contenía el montaje de preparativos de 20 segundos. Se identifica como preparación previa a la noche. El fragmento musical se limita a los primeros 45 segundos del original. No se añadieron músicas ni testimonios.

Se conservan los originales recuperados en work/comedy-media/originales. Se descartaron de la selección pública un clip desenfocado y duplicados. El contenido editorial reproducible está en scripts/archivo/content/la-perigalla-comedy.json. Las subidas multimedia permanecen fuera de Git, según las reglas existentes del proyecto.

## Cambios y comprobaciones

El editor admite una imagen previa opcional para cada vídeo, validada como URL de imagen y comprobada al publicar. La página utiliza esa imagen como poster. Los cuatro clips cargan únicamente a petición del visitante, con controles y reproducción móvil.

Primero se validó en staging y después se publicó en producción. Las 31 pruebas locales del archivo pasan, incluidas persistencia de poster y rechazo de URL insegura. Diez recursos públicos responden 200 y coinciden por SHA-256 con el manifiesto. Los cuatro vídeos se reprodujeron realmente en Chrome sin errores. Se probaron el visor de fotografías, la colección y el ancho móvil de 390 px, sin imágenes rotas ni desbordamiento. No se observaron errores de consola.

Evidencias, manifiesto y copias anteriores verificadas: outputs/archivo-experiencias/comedy. La publicación guardó copia de los cuatro archivos sustituidos y de la fila editorial anterior; las rutas de los nuevos medios estaban libres. Para revertir, comprobar primero cambios posteriores y restaurar solamente estos archivos y la historia Comedy; no restaurar una base completa sobre nuevas ventas.
