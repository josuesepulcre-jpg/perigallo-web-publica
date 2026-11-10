<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/api/src/bootstrap.php';

use Perigallo\Ticketing\AdminAuth;

AdminAuth::start();
$session = AdminAuth::sessionPayload();
if (!$session['authenticated'] || $session['role'] !== 'admin') {
    header('Location: /admin/login/?next=%2Fadmin%2Finstagram%2Fagent%2F', true, 302);
    exit;
}
session_write_close();
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https://perigallo.com blob: data:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
require __DIR__ . '/view.php';
