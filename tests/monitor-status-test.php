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
echo "OK: freshness, strict types, future timestamps, invalid state and failures\n";
