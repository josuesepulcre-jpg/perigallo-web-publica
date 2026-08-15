<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\HoldedClient;
use Perigallo\Ticketing\HoldedSyncService;

// Recupera solo ventas reales pagadas que nunca se sincronizaron o cuyo
// reintento es seguro. No toca synced, processing ni requires_review.
$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$limit = 100;
foreach ($arguments as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
        $limit = (int) $matches[1];
    }
}

$result = (new HoldedSyncService(Database::pdo(), new HoldedClient()))
    ->requeueRecoverableOrders($limit, $apply);
$result['mode'] = $apply ? 'applied' : 'dry_run';
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
