<?php
declare(strict_types=1);
// Development router only. Bind the PHP server to loopback; Apache uses .htaccess.
$root = dirname(__DIR__, 2);
$config = parse_ini_file($root . '/.env', false, INI_SCANNER_RAW) ?: [];
if (($config['APP_ENV'] ?? '') !== 'staging' || ($config['DB_DATABASE'] ?? '') !== 'perigallo_archivo_local') {
    http_response_code(503); exit('Staging configuration required.');
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
if (preg_match('#(?:^|/)\.|^/(?:docs|scripts|tests|database)(?:/|$)|^/api/(?:src|scripts|cron)(?:/|$)#i', $path)) {
    http_response_code(404); exit('No encontrado');
}
if (preg_match('#^/experiencias/vividas/([a-z0-9-]+)/?$#', $path, $match)) { $_GET['slug'] = $match[1]; require $root . '/experiencias/vivida.php'; return true; }
if (str_starts_with($path, '/api/')) { require $root . '/api/index.php'; return true; }
$redirects = ['bodas'=>'/bodas-alicante/','celebraciones'=>'/celebraciones-familiares-alicante/','pop-up'=>'/experiencias/','reservar'=>'/experiencias/','la-finca'=>'/finca-la-llaguna/','eventos'=>'/experiencias/'];
if (isset($redirects[trim($path, '/')])) { header('Location: ' . $redirects[trim($path, '/')], true, 301); return true; }
if (in_array(trim($path,'/'), ['formulario','solicitud-evento'],true)) {
    header('Location: https://suite.perigallo.com/solicitud?origen=web-perigallo', true, 302); return true;
}
$relative = ltrim($path, '/');
if ($path === '/admin/acceso/') $relative = 'admin/entradas/acceso/index.html';
elseif (preg_match('#^/admin/eventos/(\d+)/editar/?$#', $path, $m)) { $_GET['id']=$m[1]; $relative='admin/entradas/evento/index.html'; }
elseif (preg_match('#^/(?:eventos|experiencias)/([a-z0-9-]+)/?$#', $path, $m)) { $_GET['slug']=$m[1]; $relative='eventos/evento.html'; }
$file = realpath($root . '/' . $relative);
if ($file && is_dir($file)) $file = realpath($file . '/index.html');
if (!$file || !str_starts_with($file, $root . '/') || !is_file($file)) {
    http_response_code(404); $file = $root . '/404.html';
}
$ext=pathinfo($file,PATHINFO_EXTENSION);
$types=['html'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','json'=>'application/json','svg'=>'image/svg+xml','webp'=>'image/webp','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','mp4'=>'video/mp4','ttf'=>'font/ttf','woff2'=>'font/woff2','ico'=>'image/x-icon'];
if (!isset($types[$ext])) { http_response_code(404); exit('No encontrado'); }
header('Content-Type: '.$types[$ext]);
header('Cache-Control: no-store');
readfile($file);
return true;
