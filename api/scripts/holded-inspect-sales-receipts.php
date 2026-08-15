<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Perigallo\Ticketing\Database;
use Perigallo\Ticketing\HoldedClient;

// Herramienta de diagnóstico sin escrituras. Solo devuelve identificadores,
// importes, fechas y referencias técnicas; no imprime nombres ni correos.
$pdo = Database::pdo();
$orders = $pdo->query(
    'SELECT id, redsys_order, DATE(paid_at) AS paid_date, total_cents
     FROM ticket_orders
     WHERE is_test = 0 AND environment = "production"
       AND (status = "paid" OR payment_status = "paid")
       AND holded_status = "requires_review"
       AND holded_document_type = "salesreceipt"
     ORDER BY paid_at ASC'
)->fetchAll();

if (!$orders) {
    echo json_encode(['orders' => 0, 'matches' => [], 'remote_candidates' => []], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

$start = (string) $orders[0]['paid_date'];
$end = (string) $orders[count($orders) - 1]['paid_date'];
$page = (new HoldedClient())->salesReceipts([
    'limit' => 200,
    'start_date' => $start,
    'end_date' => $end,
]);
$items = is_array($page['items'] ?? null) ? $page['items'] : [];
$references = array_flip(array_map(static fn (array $order): string => 'Pedido Perigallo ' . $order['redsys_order'], $orders));
$matches = [];
$candidates = [];
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $summary = [
        'id' => (string) ($item['id'] ?? ''),
        'document_number' => (string) ($item['document_number'] ?? ''),
        'date' => (string) ($item['date'] ?? ''),
        'total' => (string) ($item['total'] ?? ''),
        'status' => (string) ($item['status'] ?? ''),
        'notes' => (string) ($item['notes'] ?? ''),
        'description' => (string) ($item['description'] ?? ''),
    ];
    if (isset($references[$summary['notes']]) || isset($references[$summary['description']])) {
        $matches[] = $summary;
        continue;
    }
    $candidates[] = $summary;
}

echo json_encode([
    'orders' => count($orders),
    'range' => ['start' => $start, 'end' => $end],
    'matches' => $matches,
    'remote_candidates' => $candidates,
], JSON_UNESCAPED_UNICODE), PHP_EOL;
