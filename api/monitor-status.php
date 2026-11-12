<?php
declare(strict_types=1);

// Reads the local collector's reduced state. It never runs a check on demand.
require __DIR__.'/src/MonitorStatus.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','HEAD'],true)) {
    http_response_code(405); header('Allow: GET, HEAD'); echo '{"ok":false}'; return;
}
$file='/var/www/vhosts/perigallo.com/private/ops-monitor/public.json';
$raw=is_readable($file) && filesize($file)<=1024 ? @file_get_contents($file) : null;
$ok=\Perigallo\Ticketing\MonitorStatus::healthy(is_string($raw)?$raw:null,time());
http_response_code($ok?200:503);
echo $ok?'{"ok":true}':'{"ok":false}';
