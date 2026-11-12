<?php
declare(strict_types=1);
require __DIR__.'/../api/src/MonitorStatus.php';
use Perigallo\Ticketing\MonitorStatus;
$now=1800000000;
foreach ([
    [true,['version'=>1,'ok'=>true,'checked_at'=>$now-60]],
    [false,['version'=>1,'ok'=>true,'checked_at'=>$now-181]],
    [false,['version'=>1,'ok'=>true,'checked_at'=>$now+31]],
    [false,['version'=>1,'ok'=>false,'checked_at'=>$now]],
    [false,['version'=>1,'ok'=>'true','checked_at'=>$now]],
    [false,['version'=>1,'ok'=>true]],
] as [$expected,$state]) {
    if (MonitorStatus::healthy(json_encode($state),$now)!==$expected) throw new RuntimeException('Health signal assertion failed');
}
foreach ([null,'{broken',str_repeat('x',1025)] as $raw) {
    if (MonitorStatus::healthy($raw,$now)) throw new RuntimeException('Invalid state accepted');
}
$warning=json_encode(['version'=>1,'ok'=>false,'critical_ok'=>true,'checked_at'=>$now]);
if (MonitorStatus::healthy($warning,$now) || !MonitorStatus::healthy($warning,$now,true)) throw new RuntimeException('Warning masks the critical signal');
$critical=json_encode(['version'=>1,'ok'=>false,'critical_ok'=>false,'checked_at'=>$now]);
if (MonitorStatus::healthy($critical,$now,true)) throw new RuntimeException('Critical incident accepted');
$missing=json_encode(['version'=>1,'ok'=>true,'checked_at'=>$now]);
if (MonitorStatus::healthy($missing,$now,true)) throw new RuntimeException('Missing critical sample accepted');
$stale=json_encode(['version'=>1,'ok'=>false,'critical_ok'=>true,'checked_at'=>$now-181]);
if (MonitorStatus::healthy($stale,$now,true)) throw new RuntimeException('Stale critical sample accepted');
echo "OK: freshness, strict types, future timestamps, invalid state and failures\n";
