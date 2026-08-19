<?php
declare(strict_types=1);

// Programar cada minuto en Plesk. El callback de Redsys solo deja trabajos;
// este proceso ejecuta los canales de comunicación fuera de esa petición.
require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\Mailer;
use Perigallo\Ticketing\TicketDeliveryQueue;

$lockPath = sys_get_temp_dir() . '/perigallo-ticket-delivery.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Ticket delivery worker already running.\n");
    exit(0);
}
try {
    $limit = max(1, min(100, (int) ($argv[1] ?? 20)));
    $result = (new TicketDeliveryQueue(Database::pdo(), new Mailer()))->processDue($limit);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $error) {
    // No exponer destinatarios, documentos, QR ni secretos en la salida de cron.
    fwrite(STDERR, "Ticket delivery worker failed.\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
