<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\DatabasePressure;

// Local operational warnings only: no email, WhatsApp or customer data.
$directory = dirname(__DIR__, 3) . '/private/database-pressure';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) exit(1);
$lock = fopen($directory . '/monitor.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try {
    $stateFile = $directory . '/state.json';
    $previous = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
    try {
        $sample = DatabasePressure::sample(Database::pdo());
        $record = ['time' => date(DATE_ATOM)] + DatabasePressure::assess($sample, is_array($previous['sample'] ?? null) ? $previous['sample'] : null);
        $state = ['sample' => $sample, 'last' => $record];
        $temporary = $stateFile . '.next';
        file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
        chmod($temporary, 0600);
        rename($temporary, $stateFile);
    } catch (Throwable $error) {
        $record = ['time' => date(DATE_ATOM), 'level' => 'critical', 'event' => 'database_monitor_unavailable', 'connection_limit' => Database::isConnectionLimit($error)];
    }
    if ($record['level'] !== 'ok') {
        $log = $directory . '/warnings.jsonl';
        if (is_file($log) && filesize($log) > 1048576) rename($log, $log . '.previous');
        file_put_contents($log, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        chmod($log, 0600);
    }
    echo json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    flock($lock, LOCK_UN); fclose($lock);
}
