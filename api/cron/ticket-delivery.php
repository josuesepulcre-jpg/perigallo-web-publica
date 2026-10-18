<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\Mailer;
use Perigallo\Ticketing\TicketDeliveryDiagnostics;
use Perigallo\Ticketing\TicketDeliveryQueue;

$lock = null;
$stage = 'arguments';
$exitCode = 0;
try {
    $dryRun = false;
    $limit = 20;
    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, ['--dry-run', '--health'], true)) $dryRun = true;
        elseif (ctype_digit($arg)) $limit = max(1, min(100, (int) $arg));
        else throw new RuntimeException('unknown_worker_argument');
    }
    $stage = 'lock';
    $lock = fopen(sys_get_temp_dir() . '/perigallo-ticket-delivery.lock', 'c');
    if ($lock === false) throw new RuntimeException('worker_lock_unavailable');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, "{\"ok\":true,\"skipped\":\"already_running\"}\n");
    } else {
        $stage = 'database_connect';
        // No rapid reconnect loop during database overload. The next cron run
        // retries the persistent queue; no job has been claimed at this point.
        $pdo = Database::pdo();
        $queue = new TicketDeliveryQueue($pdo, new Mailer());
        $stage = $dryRun ? 'inspect_read_only' : 'process_due';
        $result = $dryRun ? $queue->inspect() : $queue->processDue($limit);
        $record = ['ok' => true, 'time' => date(DATE_ATOM), 'php' => PHP_VERSION] + $result;
        fwrite(STDOUT, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        if (!$dryRun && ($result['failed'] ?? 0) > 0) $exitCode = 2;
    }
} catch (Throwable $error) {
    $diagnostic = TicketDeliveryDiagnostics::error($error, $stage);
    TicketDeliveryDiagnostics::log($diagnostic);
    fwrite(STDERR, json_encode(['ok' => false] + $diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
exit($exitCode);
