<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\HoldedClient;
use Perigallo\Ticketing\HoldedException;
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
    // Diagnóstico útil para Plesk sin revelar cabeceras, claves ni datos fiscales.
    $diagnostic = [
        'ok' => false,
        'error_type' => (new ReflectionClass($error))->getShortName(),
        'safe_code' => $error instanceof HoldedException ? $error->safeCode : 'holded_cron_internal',
        'http_status' => $error instanceof HoldedException ? $error->httpStatus : null,
        'order_id' => null,
    ];
    fwrite(STDERR, json_encode($diagnostic, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
