<?php
declare(strict_types=1);
require __DIR__.'/../api/src/SupervisorMcp.php';
use Perigallo\Ticketing\SupervisorMcp as M;
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$now=1794488082;
$called=0; $snapshot=static function() use (&$called): array { $called++; return ['fixture'=>true]; };
$send=static fn(string $method,array $params=[]): array => M::dispatch(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params],$snapshot);
$init=$send('initialize',['protocolVersion'=>'2025-03-26']);
check($init['result']['protocolVersion']==='2025-03-26','Initialization negotiation');
check(is_object($send('ping')['result']),'Ping must return a JSON object');
$tools=$send('tools/list')['result']['tools'];
check(count($tools)===1 && $tools[0]['annotations']['readOnlyHint']===true && $tools[0]['annotations']['destructiveHint']===false,'Read-only tool only');
check($tools[0]['inputSchema']['additionalProperties']===false,'No arbitrary inputs');
foreach (['https://evil.test/','http://127.0.0.1/','https://perigallo.com/api/redsys/notification'] as $target) {
    check($send('tools/call',['name'=>'read_perigallo_status','arguments'=>(object)['url'=>$target]])['error']['code']===-32602,'Arbitrary URL rejected');
}
check($send('tools/call',['name'=>'read_perigallo_status','arguments'=>['not-an-object']])['error']['code']===-32602,'Array arguments rejected');
check($send('tools/call',['name'=>'charge_card'])['error']['code']===-32602,'Unknown tool rejected');
check(M::dispatch(['jsonrpc'=>'2.0','method'=>'tools/call','params'=>['name'=>'read_perigallo_status']],$snapshot)===null,'Notification acknowledged');
check($called===0,'Rejected requests and notifications must not probe');
check($send('tools/call',['name'=>'read_perigallo_status','arguments'=>(object)[]])['result']['structuredContent']===['fixture'=>true] && $called===1,'Explicit read call');
$raw=json_encode(['version'=>1,'checked_at'=>$now,'ok'=>false,'critical_ok'=>true,'private_detail'=>'must never leak']);
$signal=M::signal($raw,$now);
check($signal['status']==='WARN' && $signal['critical_status']==='OK','Warning must not mask healthy critical signal');
check(!str_contains(json_encode($signal),'must never leak'),'Private fields excluded');
check(M::signal($raw,$now+181)['critical_status']==='NO VERIFICADO','Stale signal fails closed');
check(M::signal($raw,$now-31)['critical_status']==='NO VERIFICADO','Future signal fails closed');
foreach ([null,'{}','not-json',json_encode(['version'=>1,'checked_at'=>$now,'ok'=>'yes','critical_ok'=>true])] as $bad) {
    check(M::signal($bad,$now)['status']==='NO VERIFICADO','Malformed signal fails closed');
}
$directory=sys_get_temp_dir().'/perigallo-mcp-test-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
try {
    $count=0; $probe=static function() use (&$count): array { $count++; return [['url'=>'https://fixture.test/','status'=>'OK']]; };
    M::cachedSites($directory,$now,$probe); M::cachedSites($directory,$now+30,$probe);
    check($count===1,'Repeated calls use cache');
    M::cachedSites($directory,$now+61,$probe); check($count===2,'Old cache refreshes');
    $lock=fopen($directory.'/public-sites.lock','c'); flock($lock,LOCK_EX);
    check(M::cachedSites($directory,$now+62,$probe)===null && $count===2,'Concurrent probes do not multiply outbound requests');
    flock($lock,LOCK_UN); fclose($lock);
    check((fileperms($directory.'/public-sites.json')&0777)===0600,'Cache remains private');
} finally { foreach (glob($directory.'/*') as $file) unlink($file); rmdir($directory); }
check(count(M::DOMAINS)===11 && count(array_unique(M::DOMAINS))===11,'Fixed domain coverage');
$source=file_get_contents(__DIR__.'/../api/supervisor-mcp.php').file_get_contents(__DIR__.'/../api/src/SupervisorMcp.php');
check(!preg_match('/bootstrap|Database::|\.env|latest\.json|PDO|CURLOPT_POST|FOLLOWLOCATION=>true/',$source),'No business bootstrap, DB, secrets, private detail, POST or redirects');
echo "OK: MCP negotiation, read-only scope, input rejection, signal freshness/privacy and bounded cache\n";
