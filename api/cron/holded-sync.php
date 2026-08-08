<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\HoldedClient;
use Perigallo\Ticketing\HoldedSyncService;

$lockPath = sys_get_temp_dir() . '/perigallo-holded-sync.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Holded sync already running.\n");
    exit(0);
}
try {
    $limit = max(1, min(100, (int) ($argv[1] ?? 20)));
    $result = (new HoldedSyncService(Database::pdo(), new HoldedClient()))->due($limit);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, "Holded sync failed.\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
