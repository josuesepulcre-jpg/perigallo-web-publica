<?php
declare(strict_types=1);
namespace Perigallo\Ticketing;

/** Anonymous MCP integration exposing only public availability and reduced health. */
final class SupervisorMcp
{
    public const DOMAINS = ['perigallo.com','suite.perigallo.com','reservas.perigallo.com','lab.perigallo.com','consejo.perigallo.com','staff.perigallo.com','revision-web.perigallo.com','fincalallaguna.com','fincalallaguna.es','josuesepulcre.com','asistente.josuesepulcre.com'];
    public static function tools(): array
    {
        return [['name'=>'read_perigallo_status','title'=>'Estado público de Perigallo',
            'description'=>'Lee disponibilidad HTTPS/TLS de 11 portadas fijas y la señal mínima del monitor interno. Sin datos de clientes ni operaciones. Incluye fecha y caducidad; no prueba un cobro bancario ni una reserva completa.',
            'inputSchema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>true],
            '_meta'=>['securitySchemes'=>[['type'=>'noauth']]]]];
    }
    public static function dispatch(array $request, callable $snapshot): ?array
    {
        $id=$request['id'] ?? null;
        $reply=static fn(array|object $result): array => ['jsonrpc'=>'2.0','id'=>$id,'result'=>$result];
        $error=static fn(int $code,string $message): array => ['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]];
        if (($request['jsonrpc'] ?? null)!=='2.0' || !is_string($request['method'] ?? null)
            || (array_key_exists('id',$request) && !is_string($id) && !is_int($id))) return $error(-32600,'Invalid Request');
        if (!array_key_exists('id',$request)) {
            return null; // Notifications never perform work.
        }
        switch ($request['method']) {
            case 'initialize':
                $version=$request['params']['protocolVersion'] ?? '';
                if (!in_array($version,['2024-11-05','2025-03-26','2025-06-18','2025-11-25','2026-07-28'],true)) $version='2025-03-26';
                return $reply(['protocolVersion'=>$version,'capabilities'=>['tools'=>['listChanged'=>false]],
                    'serverInfo'=>['name'=>'perigallo-public-supervisor','version'=>'1.0.0'],
                    'instructions'=>'Solo estados públicos agregados. Comprueba checked_at y valid_until. No equivale a una operación bancaria ni a disponibilidad mundial.']);
            case 'ping': return $reply((object)[]);
            case 'tools/list': return $reply(['tools'=>self::tools()]);
            case 'tools/call':
                $params=$request['params'] ?? null;
                if (!is_array($params) || ($params['name'] ?? '')!=='read_perigallo_status') return $error(-32602,'Unknown tool');
                $args=$params['arguments'] ?? (object)[];
                if (!is_object($args) || count(get_object_vars($args))!==0) return $error(-32602,'This tool accepts no arguments');
                $state=$snapshot();
                return $reply(['content'=>[['type'=>'text','text'=>json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]],
                    'structuredContent'=>$state,'isError'=>false]);
            default: return $error(-32601,'Method not found');
        }
    }
    public static function signal(?string $raw,int $now): array
    {
        $source='https://perigallo.com/api/monitor-status.php';
        $state=$raw!==null && strlen($raw)<=1024 ? json_decode($raw,true):null;
        $valid=is_array($state) && ($state['version'] ?? null)===1 && is_int($state['checked_at'] ?? null)
            && $state['checked_at']>=$now-180 && $state['checked_at']<=$now+30
            && is_bool($state['ok'] ?? null) && is_bool($state['critical_ok'] ?? null);
        return ['source'=>$source,'status'=>$valid?($state['ok']?'OK':'WARN'):'NO VERIFICADO',
            'critical_status'=>$valid?($state['critical_ok']?'OK':'CRIT'):'NO VERIFICADO',
            'checked_at'=>$valid?gmdate('c',$state['checked_at']):null,
            'valid_until'=>$valid?gmdate('c',$state['checked_at']+180):null,
            'scope'=>'Señal booleana de la sonda; sin métricas privadas. Un aviso general no acredita una caída.'];
    }
    public static function publicSites(): array
    {
        $multi=curl_multi_init(); $handles=[];
        foreach (self::DOMAINS as $domain) {
            $h=curl_init('https://'.$domain.'/');
            $writer=(static function(): callable {
                $bytes=0;
                return static function($handle,string $chunk) use (&$bytes): int {
                    $bytes+=strlen($chunk); return $bytes>524288?0:strlen($chunk);
                };
            })();
            curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>5000,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CERTINFO=>true,
                CURLOPT_USERAGENT=>'PerigalloReadOnlySupervisor/1.0',
                CURLOPT_WRITEFUNCTION=>$writer]);
            curl_multi_add_handle($multi,$h); $handles[$domain]=$h;
        }
        do { $status=curl_multi_exec($multi,$active); if ($active) curl_multi_select($multi,0.1); }
        while ($active && $status===CURLM_OK);
        $sites=[];
        foreach ($handles as $domain=>$h) {
            $info=curl_getinfo($h); $code=(int)($info['http_code'] ?? 0); $error=curl_errno($h);
            $certs=curl_getinfo($h,CURLINFO_CERTINFO); $days=null;
            if (!empty($certs[0]['Cert'])) {
                $cert=openssl_x509_parse($certs[0]['Cert']);
                if (isset($cert['validTo_time_t'])) $days=(int)floor(($cert['validTo_time_t']-time())/86400);
            }
            $sites[]=['url'=>'https://'.$domain.'/','checked_at'=>gmdate('c'),
                'status'=>$error===0 && $code>=200 && $code<400?'OK':($code>0?'WARN':'NO VERIFICADO'),
                'http_code'=>$code>0?$code:null,'redirect_not_followed'=>$code>=300 && $code<400,'tls_verified'=>$error===0 && ($info['ssl_verify_result'] ?? -1)===0,
                'tls_days_remaining'=>$days,'tls_status'=>$days===null?'NO VERIFICADO':($days<=7?'CRIT':($days<=30?'WARN':'OK'))];
            curl_multi_remove_handle($multi,$h);
        }
        curl_multi_close($multi); return $sites;
    }
    public static function cachedSites(string $directory,int $now,callable $probe): ?array
    {
        // Application-owned private cache bounds work to one batch per minute.
        $lock=@fopen($directory.'/public-sites.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return null; }
        try {
            @chmod($directory.'/public-sites.lock',0600);
            $file=$directory.'/public-sites.json';
            $raw=is_file($file) && filesize($file)<=16384?@file_get_contents($file):false;
            $state=is_string($raw)?json_decode($raw,true):null;
            if (is_array($state) && is_int($state['checked_at'] ?? null) && $state['checked_at']>=$now-60 && $state['checked_at']<=$now
                && is_array($state['sites'] ?? null)) return $state;
            $state=['checked_at'=>$now,'valid_until'=>$now+90,'sites'=>$probe()];
            $temp=$file.'.tmp';
            if (@file_put_contents($temp,json_encode($state,JSON_THROW_ON_ERROR))!==false) { @chmod($temp,0600); @rename($temp,$file); }
            return $state;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
