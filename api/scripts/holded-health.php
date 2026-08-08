<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\HoldedClient;
use Perigallo\Ticketing\HoldedSyncService;

// Comprobación local y sin escritura: no realiza peticiones externas ni expone secretos.
fwrite(STDOUT, json_encode((new HoldedSyncService(Database::pdo(), new HoldedClient()))->health(), JSON_UNESCAPED_UNICODE) . PHP_EOL);
