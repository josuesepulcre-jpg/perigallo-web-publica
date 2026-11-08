# Estudio privado de Instagram

Entrada: /admin/instagram/. Reutiliza la sesión PHP de administración y exige rol admin en cada petición, incluidos todos los recursos. Los usuarios de control de acceso no tienen acceso al estudio.

Los recursos del estudio se conservan fuera del document root en /var/www/vhosts/perigallo.com/private/instagram-studio/current/studio. El archivo live.php vive en la carpeta privada y debe desplegarse junto con los recursos. No copiar recursos a carpetas públicas. La página, imágenes, vídeos y SVG pasan por index.php/PATH_INFO, con no-store y noindex. Los vídeos admiten peticiones Range.

Las notas compartidas se guardan en notes.json fuera del document root, con CSRF, bloqueo y control de versiones para evitar sobrescrituras entre sesiones. Este archivo y connection.json deben conservarse en cambios de versión.

La conexión de lectura preparada usa Instagram API with Instagram Login, graph.instagram.com v25.0, con INSTAGRAM_ACCESS_TOKEN en el entorno o access_token en connection.json privado. Requiere autorización de la cuenta profesional somosperigallo y el permiso de lectura instagram_business_basic. No reutilizar el token de WhatsApp. El servidor comprueba el nombre de usuario antes de mostrar medios. Actualiza con caché de 15 minutos mientras se usa el estudio. Si falta autorización o caduca, muestra el estado real. No hay publicación automática ni permisos de escritura en Instagram. La integración con Meta queda pendiente de configuración y prueba real hasta disponer de autorización.

Comprobaciones realizadas: página anónima redirigida al login; recursos y notas anónimos bloqueados; rol de escáner bloqueado; acceso admin; no-store; traversal; CSRF; persistencia de notas; conflicto de versión; rangos de vídeo y rangos inválidos. Verificación HTTPS posterior al despliegue requerida.
