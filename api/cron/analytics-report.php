<?php
declare(strict_types=1);

// Ejecutar desde Plesk cada hora. La configuración de /admin/analitica decide
// qué informes deben enviarse y evita duplicados por período.
require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Analytics;
use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\Mailer;

$lockPath = sys_get_temp_dir() . '/perigallo-analytics-report.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Analytics report already running.\n");
    exit(0);
}

try {
    $result = (new Analytics(Database::pdo(), new Mailer()))->sendDueReports();
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Analytics report failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
