<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class Ticketing
{
    public function __construct(
        private PDO $pdo,
        private Redsys $redsys,
        private Mailer $mailer
    ) {
    }

    public function listEvents(): array
    {
        $stmt = $this->pdo->query(
            'SELECT e.*, MIN(tt.price_cents) AS price_from_cents
             FROM events e
             LEFT JOIN ticket_types tt ON tt.event_id = e.id AND tt.active = 1
             WHERE e.visible = 1 AND e.status IN ("published", "sold_out")
             GROUP BY e.id
             ORDER BY e.starts_at ASC'
        );
        return array_map([$this, 'publicEvent'], $stmt->fetchAll());
    }

    public function getEventBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE slug = ? AND visible = 1 LIMIT 1');
        $stmt->execute([$slug]);
        $event = $stmt->fetch();
        if (!$event) {
            return null;
        }
        $types = $this->ticketTypesForEvent((int) $event['id']);
        $event = $this->publicEvent($event);
        $event['ticket_types'] = $types;
        return $event;
    }

    public function createOrder(array $data): array
    {
        require_fields($data, ['event_slug', 'first_name', 'last_name', 'email', 'phone', 'items']);
        if (empty($data['privacy_accepted']) || empty($data['terms_accepted'])) {
            throw new RuntimeException('Debes aceptar privacidad y condiciones de compra.');
        }
        if (!is_array($data['items']) || count($data['items']) === 0) {
            throw new RuntimeException('Selecciona al menos una entrada.');
        }

        $this->pdo->beginTransaction();
        try {
            $event = $this->findEventForSale((string) $data['event_slug']);
            $reservationMinutes = max(5, (int) (env_value('TICKET_RESERVATION_MINUTES', '30') ?? '30'));
            $expires = (new DateTimeImmutable('now'))->add(new DateInterval('PT' . $reservationMinutes . 'M'))->format('Y-m-d H:i:s');
            $publicToken = public_token();
            $redsysOrder = $this->nextRedsysOrder();

            $orderStmt = $this->pdo->prepare(
                'INSERT INTO ticket_orders
                 (public_token, redsys_order, first_name, last_name, name, email, phone, subtotal_cents, total_cents, currency, status, reservation_expires_at, ip_address, user_agent, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, "pending", ?, ?, ?, NOW(), NOW())'
            );
            $firstName = clean_string((string) $data['first_name'], 120);
            $lastName = clean_string((string) $data['last_name'], 160);
            $email = mb_strtolower(clean_string((string) $data['email'], 190));
            $phone = clean_string((string) $data['phone'], 60);
            $name = trim($firstName . ' ' . $lastName);
            $orderStmt->execute([
                $publicToken,
                $redsysOrder,
                $firstName,
                $lastName,
                $name,
                $email,
                $phone,
                env_value('REDSYS_CURRENCY', '978'),
                $expires,
                client_ip(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
            $orderId = (int) $this->pdo->lastInsertId();

            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $typeId = (int) ($item['ticket_type_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($typeId <= 0 || $quantity <= 0) {
                    continue;
                }
                $type = $this->lockTicketType($typeId, (int) $event['id']);
                if (!$type) {
                    throw new RuntimeException('Tipo de entrada no disponible.');
                }
                $min = (int) $type['min_quantity'];
                $max = (int) $type['max_per_order'];
                if ($quantity < $min || $quantity > $max) {
                    throw new RuntimeException('Cantidad no permitida para ' . $type['name'] . '.');
                }
                $available = $this->availableForType($typeId, (int) $type['capacity']);
                if ($quantity > $available) {
                    throw new RuntimeException('No quedan suficientes entradas para ' . $type['name'] . '.');
                }
                $lineTotal = $quantity * (int) $type['price_cents'];
                $subtotal += $lineTotal;
                $itemStmt = $this->pdo->prepare(
                    'INSERT INTO ticket_order_items
                     (order_id, event_id, ticket_type_id, ticket_type_name, quantity, unit_price_cents, total_cents, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $itemStmt->execute([$orderId, $event['id'], $typeId, $type['name'], $quantity, $type['price_cents'], $lineTotal]);
            }

            if ($subtotal <= 0) {
                throw new RuntimeException('El pedido no contiene entradas validas.');
            }

            $this->pdo->prepare('UPDATE ticket_orders SET subtotal_cents = ?, total_cents = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$subtotal, $subtotal, $orderId]);

            $this->pdo->prepare(
                'INSERT INTO payment_attempts
                 (order_id, redsys_order, environment, amount_cents, currency, signature_version, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, "created", NOW(), NOW())'
            )->execute([
                $orderId,
                $redsysOrder,
                env_value('REDSYS_ENV', 'test'),
                $subtotal,
                env_value('REDSYS_CURRENCY', '978'),
                env_value('REDSYS_SIGNATURE_VERSION', 'HMAC_SHA256_V1'),
            ]);

            $this->pdo->prepare('UPDATE ticket_orders SET status = "payment_processing", updated_at = NOW() WHERE id = ?')
                ->execute([$orderId]);

            $this->pdo->commit();

            return [
                'order' => $this->getOrderByToken($publicToken),
                'payment' => $this->redsysForm($redsysOrder, $subtotal, $event),
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getOrderByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE public_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }

        $items = $this->pdo->prepare('SELECT * FROM ticket_order_items WHERE order_id = ? ORDER BY id ASC');
        $items->execute([(int) $order['id']]);

        $tickets = $this->pdo->prepare(
            'SELECT t.public_code, t.status, t.issued_at, e.title AS event_title, e.starts_at, e.location, toi.ticket_type_name
             FROM tickets t
             JOIN events e ON e.id = t.event_id
             JOIN ticket_order_items toi ON toi.id = t.order_item_id
             WHERE toi.order_id = ?
             ORDER BY t.id ASC'
        );
        $tickets->execute([(int) $order['id']]);

        return [
            'token' => $order['public_token'],
            'status' => $this->effectiveOrderStatus($order),
            'name' => $order['name'],
            'email' => $order['email'],
            'phone' => $order['phone'],
            'total_cents' => (int) $order['total_cents'],
            'currency' => $order['currency'],
            'reservation_expires_at' => $order['reservation_expires_at'],
            'paid_at' => $order['paid_at'],
            'items' => $items->fetchAll(),
            'tickets' => $tickets->fetchAll(),
        ];
    }

    public function processRedsysNotification(array $post): array
    {
        $merchantParameters = (string) ($post['Ds_MerchantParameters'] ?? $post['Ds_MerchantParameters'.PHP_EOL] ?? '');
        $signature = (string) ($post['Ds_Signature'] ?? '');
        if ($merchantParameters === '' || $signature === '') {
            throw new RuntimeException('Notificacion Redsys incompleta.');
        }
        $params = $this->redsys->decodeMerchantParameters($merchantParameters);
        $orderNumber = (string) ($params['DS_ORDER'] ?? '');
        if ($orderNumber === '' || !$this->redsys->validateSignature($merchantParameters, $signature, $orderNumber)) {
            throw new RuntimeException('Firma Redsys invalida.');
        }

        $notificationHash = hash('sha256', $merchantParameters . '|' . $signature);
        $this->pdo->beginTransaction();
        try {
            $attemptStmt = $this->pdo->prepare('SELECT * FROM payment_attempts WHERE redsys_order = ? FOR UPDATE');
            $attemptStmt->execute([$orderNumber]);
            $attempt = $attemptStmt->fetch();
            if (!$attempt) {
                throw new RuntimeException('Intento de pago no encontrado.');
            }
            $orderStmt = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE id = ? FOR UPDATE');
            $orderStmt->execute([(int) $attempt['order_id']]);
            $order = $orderStmt->fetch();
            if (!$order) {
                throw new RuntimeException('Pedido no encontrado.');
            }

            $amount = (int) ($params['DS_AMOUNT'] ?? -1);
            $currency = (string) ($params['DS_CURRENCY'] ?? '');
            $merchantCode = (string) ($params['DS_MERCHANTCODE'] ?? '');
            $terminal = (string) ($params['DS_TERMINAL'] ?? '');
            $transactionType = (string) ($params['DS_TRANSACTIONTYPE'] ?? '');
            $responseCode = str_pad((string) ($params['DS_RESPONSE'] ?? '9999'), 4, '0', STR_PAD_LEFT);

            if ($amount !== (int) $order['total_cents']) {
                throw new RuntimeException('Importe Redsys no coincide.');
            }
            if ($currency !== (string) $order['currency']) {
                throw new RuntimeException('Moneda Redsys no coincide.');
            }
            if ($merchantCode !== (string) env_value('REDSYS_MERCHANT_CODE', '')) {
                throw new RuntimeException('Comercio Redsys no coincide.');
            }
            if ($terminal !== (string) env_value('REDSYS_TERMINAL', '1')) {
                throw new RuntimeException('Terminal Redsys no coincide.');
            }
            if ($transactionType !== (string) env_value('REDSYS_TRANSACTION_TYPE', '0')) {
                throw new RuntimeException('Tipo de operacion Redsys no coincide.');
            }

            $accepted = ctype_digit($responseCode) && (int) $responseCode >= 0 && (int) $responseCode <= 99;
            $attemptStatus = $accepted ? 'accepted' : 'denied';
            $orderStatus = $accepted ? 'paid' : 'denied';

            $this->pdo->prepare(
                'UPDATE payment_attempts
                 SET response_code = ?, authorisation_code = ?, status = ?, notification_hash = ?, notification_received_at = NOW(), raw_response = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $responseCode,
                (string) ($params['DS_AUTHORISATIONCODE'] ?? ''),
                $attemptStatus,
                $notificationHash,
                json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $attempt['id'],
            ]);

            if ($order['status'] !== 'paid') {
                $this->pdo->prepare('UPDATE ticket_orders SET status = ?, paid_at = IF(? = "paid", NOW(), paid_at), updated_at = NOW() WHERE id = ?')
                    ->execute([$orderStatus, $orderStatus, $order['id']]);
                if ($accepted) {
                    $this->generateTicketsOnce((int) $order['id']);
                }
            }

            $this->pdo->commit();
            if ($accepted && $order['status'] !== 'paid') {
                $this->sendConfirmation((int) $order['id']);
            }
            return ['ok' => true, 'accepted' => $accepted, 'order' => $orderNumber];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function adminSummary(): array
    {
        $orders = $this->pdo->query('SELECT status, COUNT(*) AS total, COALESCE(SUM(total_cents),0) AS amount FROM ticket_orders GROUP BY status')->fetchAll();
        $events = $this->pdo->query('SELECT id, title, slug, starts_at, status, visible FROM events ORDER BY starts_at DESC LIMIT 50')->fetchAll();
        return ['orders' => $orders, 'events' => $events];
    }

    public function adminOrders(): array
    {
        return $this->pdo->query('SELECT id, public_token, redsys_order, name, email, phone, total_cents, status, reservation_expires_at, paid_at, created_at FROM ticket_orders ORDER BY id DESC LIMIT 200')->fetchAll();
    }

    public function adminCreateEvent(array $data): array
    {
        require_fields($data, ['slug', 'title', 'description', 'location', 'starts_at', 'capacity']);
        $stmt = $this->pdo->prepare(
            'INSERT INTO events
             (slug, title, subtitle, description, image_url, location, address, starts_at, ends_at, sale_starts_at, sale_ends_at, capacity, status, visible, promoter, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            clean_string((string) $data['slug'], 140),
            clean_string((string) $data['title'], 190),
            clean_string((string) ($data['subtitle'] ?? ''), 190),
            trim((string) $data['description']),
            clean_string((string) ($data['image_url'] ?? ''), 500),
            clean_string((string) $data['location'], 190),
            clean_string((string) ($data['address'] ?? ''), 255),
            (string) $data['starts_at'],
            (string) ($data['ends_at'] ?? $data['starts_at']),
            (string) ($data['sale_starts_at'] ?? now_mysql()),
            (string) ($data['sale_ends_at'] ?? $data['starts_at']),
            (int) $data['capacity'],
            clean_string((string) ($data['status'] ?? 'draft'), 40),
            !empty($data['visible']) ? 1 : 0,
            clean_string((string) ($data['promoter'] ?? 'JYD Events, S.L.'), 190),
        ]);
        return ['id' => (int) $this->pdo->lastInsertId()];
    }

    public function adminCreateTicketType(int $eventId, array $data): array
    {
        require_fields($data, ['name', 'price_cents', 'capacity']);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_types
             (event_id, name, description, price_cents, capacity, min_quantity, max_per_order, active, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $eventId,
            clean_string((string) $data['name'], 160),
            trim((string) ($data['description'] ?? '')),
            (int) $data['price_cents'],
            (int) $data['capacity'],
            max(1, (int) ($data['min_quantity'] ?? 1)),
            max(1, (int) ($data['max_per_order'] ?? 10)),
            !empty($data['active']) ? 1 : 0,
            (int) ($data['sort_order'] ?? 100),
        ]);
        return ['id' => (int) $this->pdo->lastInsertId()];
    }

    public function scanTicket(array $data): array
    {
        require_fields($data, ['event_id', 'code']);
        $eventId = (int) $data['event_id'];
        $code = clean_string((string) $data['code'], 120);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM tickets WHERE public_code = ? FOR UPDATE');
            $stmt->execute([$code]);
            $ticket = $stmt->fetch();
            $result = 'inexistente';
            if ($ticket) {
                if ((int) $ticket['event_id'] !== $eventId) {
                    $result = 'otro_evento';
                } elseif ($ticket['status'] === 'cancelled') {
                    $result = 'cancelada';
                } elseif ($ticket['status'] === 'used') {
                    $result = 'ya_utilizada';
                } elseif ($ticket['status'] === 'issued') {
                    $result = 'valida';
                    $this->pdo->prepare('UPDATE tickets SET status = "used", used_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([$ticket['id']]);
                }
            }
            $this->pdo->prepare(
                'INSERT INTO ticket_scans (ticket_id, event_id, scanned_code, result, scanned_by, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $ticket['id'] ?? null,
                $eventId,
                $code,
                $result,
                $_SESSION['admin'] ?? 'admin',
                client_ip(),
            ]);
            $this->pdo->commit();
            return ['result' => $result, 'ticket' => $ticket ?: null];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function ticketTypesForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE event_id = ? AND active = 1 ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $type) {
            $available = $this->availableForType((int) $type['id'], (int) $type['capacity']);
            $rows[] = [
                'id' => (int) $type['id'],
                'name' => $type['name'],
                'description' => $type['description'],
                'price_cents' => (int) $type['price_cents'],
                'capacity' => (int) $type['capacity'],
                'available' => $available,
                'min_quantity' => (int) $type['min_quantity'],
                'max_per_order' => (int) $type['max_per_order'],
            ];
        }
        return $rows;
    }

    private function publicEvent(array $event): array
    {
        return [
            'id' => (int) $event['id'],
            'slug' => $event['slug'],
            'title' => $event['title'],
            'subtitle' => $event['subtitle'],
            'description' => $event['description'],
            'image_url' => $event['image_url'],
            'location' => $event['location'],
            'address' => $event['address'],
            'starts_at' => $event['starts_at'],
            'ends_at' => $event['ends_at'],
            'sale_ends_at' => $event['sale_ends_at'],
            'status' => $event['status'],
            'price_from_cents' => isset($event['price_from_cents']) ? (int) $event['price_from_cents'] : null,
            'promoter' => $event['promoter'],
        ];
    }

    private function findEventForSale(string $slug): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM events
             WHERE slug = ? AND visible = 1 AND status = "published"
             AND sale_starts_at <= NOW() AND sale_ends_at >= NOW()
             LIMIT 1'
        );
        $stmt->execute([$slug]);
        $event = $stmt->fetch();
        if (!$event) {
            throw new RuntimeException('Evento no disponible para venta.');
        }
        return $event;
    }

    private function lockTicketType(int $typeId, int $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE id = ? AND event_id = ? AND active = 1 FOR UPDATE');
        $stmt->execute([$typeId, $eventId]);
        $type = $stmt->fetch();
        return $type ?: null;
    }

    private function availableForType(int $typeId, int $capacity): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(toi.quantity),0) AS qty
             FROM ticket_order_items toi
             JOIN ticket_orders tor ON tor.id = toi.order_id
             WHERE toi.ticket_type_id = ?
             AND (
                tor.status = "paid"
                OR (tor.status IN ("pending","payment_processing") AND tor.reservation_expires_at > NOW())
             )'
        );
        $stmt->execute([$typeId]);
        $reserved = (int) $stmt->fetchColumn();
        return max(0, $capacity - $reserved);
    }

    private function nextRedsysOrder(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $order = date('ymd') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM payment_attempts WHERE redsys_order = ?');
            $stmt->execute([$order]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $order;
            }
        }
        throw new RuntimeException('No se pudo generar numero de pedido Redsys.');
    }

    private function redsysForm(string $redsysOrder, int $amountCents, array $event): array
    {
        $base = app_base_url();
        $params = [
            'DS_MERCHANT_AMOUNT' => (string) $amountCents,
            'DS_MERCHANT_ORDER' => $redsysOrder,
            'DS_MERCHANT_MERCHANTCODE' => env_value('REDSYS_MERCHANT_CODE', ''),
            'DS_MERCHANT_CURRENCY' => env_value('REDSYS_CURRENCY', '978'),
            'DS_MERCHANT_TRANSACTIONTYPE' => env_value('REDSYS_TRANSACTION_TYPE', '0'),
            'DS_MERCHANT_TERMINAL' => env_value('REDSYS_TERMINAL', '1'),
            'DS_MERCHANT_MERCHANTURL' => $base . '/api/redsys/notification',
            'DS_MERCHANT_URLOK' => $base . '/entradas/pago/correcto/?order=' . rawurlencode($redsysOrder),
            'DS_MERCHANT_URLKO' => $base . '/entradas/pago/error/?order=' . rawurlencode($redsysOrder),
            'DS_MERCHANT_MERCHANTNAME' => 'Perigallo',
            'DS_MERCHANT_PRODUCTDESCRIPTION' => mb_substr('Entradas ' . preg_replace('/[^A-Za-z0-9 \\-]/', '', (string) $event['title']), 0, 120),
        ];
        return [
            'url' => $this->redsys->paymentUrl(),
            'fields' => $this->redsys->buildRedirectFields($params),
        ];
    }

    private function effectiveOrderStatus(array $order): string
    {
        if ($order['status'] === 'pending' && $order['reservation_expires_at'] && strtotime((string) $order['reservation_expires_at']) < time()) {
            return 'expired';
        }
        return (string) $order['status'];
    }

    private function generateTicketsOnce(int $orderId): void
    {
        $existing = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tickets t JOIN ticket_order_items toi ON toi.id = t.order_item_id WHERE toi.order_id = ?'
        );
        $existing->execute([$orderId]);
        if ((int) $existing->fetchColumn() > 0) {
            return;
        }
        $items = $this->pdo->prepare('SELECT * FROM ticket_order_items WHERE order_id = ? ORDER BY id ASC');
        $items->execute([$orderId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO tickets (order_item_id, event_id, ticket_type_id, public_code, qr_token_hash, status, issued_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "issued", NOW(), NOW(), NOW())'
        );
        foreach ($items->fetchAll() as $item) {
            for ($i = 0; $i < (int) $item['quantity']; $i++) {
                $token = public_token(32);
                $code = 'PG-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
                $insert->execute([
                    $item['id'],
                    $item['event_id'],
                    $item['ticket_type_id'],
                    $code,
                    hash('sha256', $token),
                ]);
            }
        }
    }

    private function sendConfirmation(int $orderId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            return;
        }
        $link = app_base_url() . '/entradas/pedido/?token=' . rawurlencode((string) $order['public_token']);
        $body = \"Hola {$order['name']},\\n\\nTu pago se ha confirmado correctamente.\\n\\nPuedes consultar y descargar tus entradas aqui:\\n{$link}\\n\\nContacto Perigallo: +34 691 499 985\\n\";
        $this->mailer->queueOrderEmail($this->pdo, $orderId, (string) $order['email'], 'Tus entradas Perigallo', $body);
    }
}
