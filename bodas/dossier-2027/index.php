<?php
declare(strict_types=1);

// The dossier and its assets live outside the document root.
$bundle = getenv('PERIGALLO_DOSSIER_DIRECTORY') ?: '/var/www/vhosts/perigallo.com/private/perigallo-dossier-bodas-2027';
$cookieName = 'perigallo_bodas_2027';
$resource = $_GET['r'] ?? 'index.html';
$isDocument = $resource === 'index.html';
$key = $isDocument ? ($_GET['acceso'] ?? '') : ($_COOKIE[$cookieName] ?? '');
$keyHash = @file_get_contents($bundle . '/access.sha256');

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");

$types = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'svg' => 'image/svg+xml',
    'ttf' => 'font/ttf',
    'webp' => 'image/webp',
];
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$validResource = is_string($resource) && preg_match('~\A(?:assets/(?:[a-zA-Z0-9_-]+/)*)?[a-zA-Z0-9_-]+\.[a-z]+\z~D', $resource);
$type = $validResource ? ($types[pathinfo($resource, PATHINFO_EXTENSION)] ?? null) : null;
$file = $validResource ? $bundle . '/content/' . $resource : '';

if (!in_array($method, ['GET', 'HEAD'], true) || !is_string($key) || !preg_match('/\A[a-zA-Z0-9_-]{32}\z/D', $key)
    || $keyHash === false || !hash_equals(trim($keyHash), hash('sha256', $key)) || !$type || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    if ($method !== 'HEAD') {
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Perigallo · Bodas 2027</title><body style="background:#191a17;color:#f3f3ee;font:1.1rem system-ui;padding:12vh 9vw"><h1>Perigallo · Bodas 2027</h1><p>Abrid el enlace completo que os ha enviado Perigallo.</p></body></html>';
    }
    exit;
}

if ($isDocument) {
    setcookie($cookieName, $key, [
        'path' => '/bodas/dossier-2027/',
        'secure' => PHP_SAPI !== 'cli-server',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
header('Content-Type: ' . $type);
if ($method === 'GET') {
    readfile($file);
}
