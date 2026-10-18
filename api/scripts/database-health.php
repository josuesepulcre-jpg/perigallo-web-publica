<?php
declare(strict_types=1);

// Read-only CLI report for Plesk. No SQL text, customer data or credentials.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;

$report = ['ok' => false, 'mode' => 'read_only', 'time' => date(DATE_ATOM), 'php' => PHP_VERSION];
$worker = dirname(__DIR__) . '/cron/ticket-delivery.php';
$report['ticket_worker_sha256'] = hash_file('sha256', $worker);
try {
    $pdo = Database::pdo();
    $report['ok'] = true;
    foreach ([
        'limits' => "SHOW VARIABLES WHERE Variable_name IN ('max_connections', 'max_user_connections', 'wait_timeout', 'interactive_timeout')",
        'activity' => "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Threads_running', 'Max_used_connections', 'Connections', 'Aborted_connects', 'Connection_errors_max_connections', 'Uptime')",
        'visible_connections' => 'SELECT COMMAND AS command, COUNT(*) AS connections, MAX(TIME) AS longest_seconds FROM information_schema.PROCESSLIST GROUP BY COMMAND',
        'delivery_queue' => 'SELECT status, COUNT(*) AS total FROM ticket_delivery_jobs GROUP BY status',
    ] as $key => $sql) {
        try {
            $report[$key] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $error) {
            $report[$key] = ['available' => false];
        }
    }
    $report['note'] = 'visible_connections depende de los permisos; Max_used_connections es histórico desde el reinicio/reset. Comparar dos informes para calcular variaciones.';
} catch (Throwable $error) {
    $report['database_connected'] = false;
    $report['connection_limit'] = Database::isConnectionLimit($error);
    $report['driver_code'] = $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null;
}
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit($report['ok'] ? 0 : 1);
