<?php
declare(strict_types=1);
require __DIR__.'/src/SupervisorMcp.php';
use Perigallo\Ticketing\SupervisorMcp;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$origin=$_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin!==null && !in_array($origin,['https://chatgpt.com','https://chat.openai.com','https://perigallo.com'],true)) {
    http_response_code(403); echo '{"error":"Origin not allowed"}'; return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') {
    http_response_code(405); header('Allow: POST'); echo '{"error":"MCP uses POST"}'; return;
}
if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]))!=='application/json') {
    http_response_code(415); echo '{"error":"application/json required"}'; return;
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>8192) {
    http_response_code(413); echo '{"error":"Request too large"}'; return;
}
$input=file_get_contents('php://input',false,null,0,8193);
try {
    if (strlen($input)>8192) throw new JsonException();
    $object=json_decode($input,false,32,JSON_THROW_ON_ERROR);
    if (!is_object($object)) throw new JsonException();
    $request=(array)$object;
    if (isset($request['params']) && is_object($request['params'])) $request['params']=(array)$request['params'];
    $result=SupervisorMcp::dispatch($request,static function(): array {
        $directory='/var/www/vhosts/perigallo.com/private/ops-monitor'; $file=$directory.'/public.json';
        $raw=is_readable($file) && filesize($file)<=1024?@file_get_contents($file):null;
        $now=time(); $sites=SupervisorMcp::cachedSites($directory,$now,[SupervisorMcp::class,'publicSites']);
        return ['observed_at'=>gmdate('c',$now),'observer'=>'Servidor Perigallo; no navegador local ni disponibilidad mundial',
            'internal_signal'=>SupervisorMcp::signal(is_string($raw)?$raw:null,$now),
            'website_observations'=>$sites ?? ['status'=>'NO VERIFICADO','reason'=>'Probe busy or cache unavailable'],
            'scope'=>'GET HTTPS de 11 portadas fijas; sin DB, credenciales, datos de clientes ni operaciones comerciales.'];
    });
    if ($result===null) { http_response_code(202); return; }
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400); echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}';
} catch (Throwable $error) {
    http_response_code(500); echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Status temporarily unavailable"}}';
}
