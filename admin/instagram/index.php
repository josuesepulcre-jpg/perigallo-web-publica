<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';
use Perigallo\Ticketing\AdminAuth;
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
AdminAuth::start();
header('Cache-Control: private, no-store, max-age=0');
$session = AdminAuth::sessionPayload();
if (!$session['authenticated'] || $session['role'] !== 'admin') {
    if (empty($_SERVER['PATH_INFO']) && empty($_GET['action'])) {
        header('Location: /admin/login/?next=%2Fadmin%2Finstagram%2F', true, 302);
    } else { http_response_code(401); echo 'Acceso privado. Inicia sesión en administración.'; }
    exit;
}
$private = '/var/www/vhosts/perigallo.com/private/instagram-studio';
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($action === 'session') { json_response(['csrf' => $_SESSION['csrf']]); exit; }
    if (in_array($action, ['drafts','draft-upload','draft-image'], true)) { require $private.'/drafts.php'; exit; }
    $stateFile = $private . '/notes.json';
    if ($action === 'notes') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            AdminAuth::requireCsrf();
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 140000) { json_response(['error'=>'Notas demasiado largas.'],413); exit; }
            $body = json_decode(file_get_contents('php://input'),true);
            if (!is_array($body) || !is_string($body['notes'] ?? null) || strlen($body['notes']) > 120000) {json_response(['error'=>'Notas inválidas.'],400);exit;}
            $lock=fopen($private.'/notes.lock','c');flock($lock, LOCK_EX);
            $old=is_file($stateFile)?json_decode(file_get_contents($stateFile),true):['version'=>0,'notes'=>''];
            if (($body['version']??-1)!==$old['version']) {flock($lock,LOCK_UN);fclose($lock);json_response(['error'=>'Otra sesión ha guardado cambios. Copia tus notas y recarga para compararlas.'],409);exit;}
            $data=['notes'=>$body['notes'],'version'=>$old['version']+1,'updated_at'=>gmdate('c')];
            $tmp=tempnam($private,'.notes-');file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_UNICODE));chmod($tmp,0600);rename($tmp,$stateFile);
            flock($lock,LOCK_UN);fclose($lock);json_response($data);exit;
        }
        json_response(is_file($stateFile)?json_decode(file_get_contents($stateFile),true):['notes'=>'','version'=>0]);exit;
    }
    if ($action === 'live') {
        require $private.'/live.php';exit;
    }
    json_response(['error'=>'No encontrado.'],404);exit;
}
session_write_close();
$base=realpath($private.'/current/studio');
$relative=rawurldecode($_SERVER['PATH_INFO']??'/index.html');
if ($relative==='/')$relative='/index.html';
if (strpos($relative,"\0")!==false || in_array('..',explode('/',$relative),true)) {http_response_code(404);exit;}
$file=$base?realpath($base.'/'.$relative):false;
$types=['html'=>'text/html; charset=utf-8','js'=>'text/javascript; charset=utf-8','css'=>'text/css; charset=utf-8','json'=>'application/json','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','mp4'=>'video/mp4','woff2'=>'font/woff2','txt'=>'text/plain; charset=utf-8'];
$ext=$file?strtolower(pathinfo($file,PATHINFO_EXTENSION)):'';
if (!$file || !is_file($file) || !str_starts_with($file,$base.'/') || !isset($types[$ext])) {http_response_code(404);exit;}
header('Content-Type: '.$types[$ext]);
// Studio HTML is maintained privately; always use the website's current icons.
if ($ext === 'html') {
    $html = file_get_contents($file);
    $html = preg_replace('~<link\b[^>]*\brel=["\'][^"\']*icon[^>]*>\s*~i', '', $html);
    $icons = <<<'HTML'
<link rel="icon" href="/favicon.ico?v=20261109-tres-barras" sizes="16x16 32x32 48x48">
<link rel="icon" href="/assets/icons/favicon-32.png?v=20261109-tres-barras" type="image/png" sizes="32x32">
<link rel="icon" href="/favicon.svg?v=20261109-tres-barras" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=20261109-tres-barras" sizes="180x180">
HTML;
    $html = preg_replace('~</head>~i', $icons . "\n</head>", $html, 1);
    header('Content-Length: '.strlen($html));
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo $html;
    exit;
}
$size=filesize($file);$start=0;$end=$size-1;
header('Accept-Ranges: bytes');
if (isset($_SERVER['HTTP_RANGE'])) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$range) || ($range[1]==='' && $range[2]==='')) {http_response_code(416);header("Content-Range: bytes */$size");exit;}
    if ($range[1]==='') {$start=max(0,$size-(int)$range[2]);} else {$start=(int)$range[1];if($range[2]!=='')$end=min($end,(int)$range[2]);}
    if($start>$end || $start>=$size) {http_response_code(416);header("Content-Range: bytes */$size");exit;}
    http_response_code(206);header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: '.($end-$start+1));
if ($_SERVER['REQUEST_METHOD']==='HEAD')exit;
$stream=fopen($file,'rb');fseek($stream,$start);$left=$end-$start+1;
while($left>0&&!feof($stream)){ $chunk=fread($stream,min(65536,$left));echo $chunk;$left-=strlen($chunk);}
fclose($stream);
