<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/src/bootstrap.php';

use Perigallo\Ticketing\{Database, Mailer, Redsys, Ticketing};

// Run against the same disposable InnoDB database used by the reservation integration tests.
if (env_value('DB_DATABASE') !== 'perigallo_reservation_qa'
    || !in_array(env_value('DB_HOST'), ['127.0.0.1', 'localhost'], true)
    || !in_array(env_value('APP_ENV'), ['test', 'local'], true)) {
    throw new RuntimeException('Event deletion requires an isolated QA database.');
}

putenv('REDSYS_ENV=test');
putenv('PAYMENT_ENVIRONMENT=sandbox');
putenv('REDSYS_MERCHANT_CODE=999008881');
putenv('REDSYS_TERMINAL=1');
putenv('REDSYS_SIGNATURE_VERSION=HMAC_SHA256_V1');
putenv('REDSYS_TEST_SECRET_KEY=' . base64_encode(str_repeat('q', 24)));

$db = Database::pdo();
if (!$db->query("SHOW TABLES LIKE 'events'")->fetchColumn()) {
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $migration) {
        $db->exec(file_get_contents($migration));
    }
}
$ticketing = new Ticketing($db, new Redsys(), new Mailer());
$requestId = static fn (): string => bin2hex(random_bytes(16));
$check = static function (mixed $actual, mixed $expected, string $description): void {
    if ($actual !== $expected) {
        throw new RuntimeException($description . ': ' . json_encode([$actual, $expected]));
    }
};
$protected = static function (int $eventId) use ($ticketing, $check): void {
    try {
        $ticketing->adminDeleteEvent($eventId, 'qa');
    } catch (RuntimeException $error) {
        $check($error->getCode(), 409, 'protected event returns a conflict');
        return;
    }
    throw new RuntimeException('A protected event could be deleted');
};

$realEventId = (int) $ticketing->adminCreateEvent(['title' => 'QA - Evento protegido'])['id'];
$protected($realEventId);

$eventId = (int) $ticketing->adminCreateEvent([
    'title' => 'QA - Evento de prueba con reserva', 'capacity' => 6, 'is_test' => true,
])['id'];
$db->prepare('UPDATE events SET status = "published" WHERE id = ?')->execute([$eventId]);
$typeId = (int) $ticketing->adminCreateTicketType($eventId, [
    'name' => 'Menú', 'price_cents' => 12000, 'capacity' => 6,
])['id'];
$db->prepare('UPDATE ticket_types SET status = "on_sale", active = 1 WHERE id = ?')->execute([$typeId]);
$reservation = $ticketing->adminCreateReservation([
    'request_id' => $requestId(), 'event_id' => $eventId, 'ticket_type_id' => $typeId,
    'first_name' => 'Cliente', 'last_name' => 'de prueba', 'phone' => '600000000', 'quantity' => 6,
], 'qa', true);
$orderId = (int) $reservation['order']['id'];
$reservation = $ticketing->adminReservationPayment($orderId, [
    'request_id' => $requestId(), 'expected_revision' => $reservation['order']['reservation_revision'],
    'amount_euros' => '180', 'method' => 'bank_transfer',
], 'qa');
$reservation = $ticketing->adminReservationLink($orderId, [
    'request_id' => $requestId(), 'expected_revision' => $reservation['order']['reservation_revision'],
    'amount_euros' => '540',
], 'qa');
$link = $reservation['links'][0];
$ticketing->startReservationLink($link['public_token']);
$reservation = $ticketing->adminReservationDetail($orderId);
$ticketing->adminCancelReservationLink($orderId, (int) $link['id'], [
    'expected_revision' => $reservation['order']['reservation_revision'],
    'confirmed_no_charge' => true, 'reason' => 'Simulación local sin Redsys',
], 'qa', true);
$ticketing->adminSetEventArchived($eventId, true);

$environment = $db->prepare('UPDATE payment_attempts SET environment = ? WHERE order_id = ?');
$environment->execute(['production', $orderId]);
$protected($eventId);
$environment->execute(['test', $orderId]);

// A failure after the order cleanup must restore the order, its receipt and its link.
$db->exec("CREATE TRIGGER qa_event_delete_failure BEFORE DELETE ON events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA event delete fault'");
try {
    try {
        $ticketing->adminDeleteEvent($eventId, 'qa');
        throw new RuntimeException('Event deletion fault was not propagated');
    } catch (PDOException $error) {
        $check(str_contains($error->getMessage(), 'QA event delete fault'), true, 'deletion reached the event');
    }
} finally {
    $db->exec('DROP TRIGGER qa_event_delete_failure');
}
$check($ticketing->adminReservationDetail($orderId)['order']['paid_cents'], 18000, 'failed deletion retains receipt');
$check($ticketing->reservationPublicLink($link['public_token'])['status'], 'cancelled', 'failed deletion retains link');

$check($ticketing->adminDeleteEvent($eventId, 'qa'), ['deleted' => true, 'purged_test_orders' => 1], 'test event can be deleted');
$check($ticketing->adminGetEvent($eventId), null, 'test event gone');
foreach (['ticket_orders', 'ticket_order_items', 'ticket_reservation_payments', 'ticket_reservation_links', 'payment_attempts'] as $table) {
    $column = $table === 'ticket_orders' ? 'id' : 'order_id';
    $statement = $db->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
    $statement->execute([$orderId]);
    $check((int) $statement->fetchColumn(), 0, "$table removed with test event");
}
$check((int) $ticketing->adminGetEvent($realEventId)['id'], $realEventId, 'real event preserved');

echo "PASS archived test event deletion, reservation references, atomic rollback and protection of real data\n";
