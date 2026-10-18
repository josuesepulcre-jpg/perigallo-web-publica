<?php
declare(strict_types=1);
require __DIR__ . '/../api/src/DatabasePressure.php';
use Perigallo\Ticketing\DatabasePressure;
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Pressure monitoring regression'); }
$base = ['limit'=>151,'connected'=>20,'running'=>1,'rejected'=>20,'uptime'=>100];
check(DatabasePressure::assess($base,null)['level']==='ok');
check(DatabasePressure::assess(array_replace($base,['connected'=>120]),$base)['level']==='warning');
check(DatabasePressure::assess(array_replace($base,['rejected'=>21,'uptime'=>101]),$base)['level']==='critical');
check(DatabasePressure::assess(array_replace($base,['rejected'=>0,'uptime'=>1]),$base)['new_rejections']===0);
check(DatabasePressure::assess(array_replace($base,['connected'=>151]),$base)['level']==='critical');
echo "Database pressure thresholds, rejection deltas and restart handling passed.\n";
