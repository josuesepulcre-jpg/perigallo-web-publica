<?php
declare(strict_types=1);

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\Mailer;
use Perigallo\Ticketing\Redsys;
use Perigallo\Ticketing\Ticketing;

require_once dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo puede ejecutarse desde la terminal.\n");
    exit(1);
}

$confirmation = $argv[1] ?? '';
if ($confirmation !== '--confirm') {
    fwrite(STDOUT, "Modo seguro: no se ha eliminado ningún dato.\n");
    fwrite(STDOUT, "Uso: php api/scripts/purge-test-ticketing-data.php --confirm\n");
    exit(0);
}

$pdo = Database::pdo();
$orders = $pdo->query('SELECT id FROM ticket_orders WHERE is_test = 1 ORDER BY id ASC')->fetchAll();

if (!$orders) {
    fwrite(STDOUT, "No hay pedidos de prueba que eliminar.\n");
    exit(0);
}

$ticketing = new Ticketing($pdo, new Redsys(), new Mailer());
foreach ($orders as $order) {
    $ticketing->adminPurgeTestOrder((int) $order['id'], 'Limpieza inicial por terminal');
}

fwrite(STDOUT, 'Eliminados ' . count($orders) . " pedido(s) de prueba y sus datos asociados.\n");
