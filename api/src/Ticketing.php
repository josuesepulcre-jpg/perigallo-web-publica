<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
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
            'SELECT e.*,
                    (SELECT MIN(tt.price_cents)
                     FROM ticket_types tt
                     WHERE tt.event_id = e.id AND tt.active = 1 AND tt.visible = 1) AS price_from_cents
             FROM events e
             WHERE e.visible = 1 AND e.unlisted = 0 AND e.link_only = 0
               AND (
                    e.status IN ("published", "sold_out")
                    OR (e.status = "scheduled" AND e.publication_at IS NOT NULL AND e.publication_at <= NOW())
               )
             ORDER BY e.starts_at ASC'
        );
        return array_map([$this, 'publicEvent'], $stmt->fetchAll());
    }

    public function getEventBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM events
             WHERE slug = ? AND visible = 1
               AND (
                    status IN ("published", "sold_out")
                    OR (status = "scheduled" AND publication_at IS NOT NULL AND publication_at <= NOW())
               )
             LIMIT 1'
        );
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
            $selectedItems = 0;
            foreach ($data['items'] as $item) {
                $typeId = (int) ($item['ticket_type_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($typeId <= 0 || $quantity <= 0) {
                    continue;
                }
                $type = $this->lockTicketType($typeId, (int) $event['id'], (string) ($data['promo_code'] ?? ''));
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
                // El importe de compra incorpora IVA y gastos de gestión mostrados al cliente.
                $taxCents = (int) round((int) $type['price_cents'] * (float) ($type['tax_rate'] ?? 0) / 100);
                $unitPrice = (int) $type['price_cents'] + $taxCents + (int) ($type['fee_cents'] ?? 0);
                $lineTotal = $quantity * $unitPrice;
                $subtotal += $lineTotal;
                $selectedItems++;
                $itemStmt = $this->pdo->prepare(
                    'INSERT INTO ticket_order_items
                     (order_id, event_id, ticket_type_id, ticket_type_name, quantity, unit_price_cents, total_cents, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $itemStmt->execute([$orderId, $event['id'], $typeId, $type['name'], $quantity, $unitPrice, $lineTotal]);
            }

            if ($selectedItems === 0) {
                throw new RuntimeException('El pedido no contiene entradas validas.');
            }

            $this->pdo->prepare('UPDATE ticket_orders SET subtotal_cents = ?, total_cents = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$subtotal, $subtotal, $orderId]);

            // Las invitaciones gratuitas emiten la entrada directamente: no deben pasar por Redsys.
            if ($subtotal === 0) {
                $this->pdo->prepare('UPDATE ticket_orders SET status = "paid", paid_at = NOW(), updated_at = NOW() WHERE id = ?')
                    ->execute([$orderId]);
                $this->generateTicketsOnce($orderId);
                $this->pdo->commit();
                $this->sendConfirmation($orderId);
                return [
                    'order' => $this->getOrderByToken($publicToken),
                    'payment' => [
                        'free' => true,
                        'url' => app_base_url() . '/entradas/pedido/?token=' . rawurlencode($publicToken),
                    ],
                ];
            }

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
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
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
        $events = $this->adminListEvents();
        return ['orders' => $orders, 'events' => $events];
    }

    public function adminOrders(): array
    {
        return $this->pdo->query('SELECT id, public_token, redsys_order, name, email, phone, total_cents, status, reservation_expires_at, paid_at, created_at FROM ticket_orders ORDER BY id DESC LIMIT 200')->fetchAll();
    }

    public function adminCreateEvent(array $data): array
    {
        $canonicalId = $this->canonicalId((string) ($data['canonical_id'] ?? ''));
        $eventType = $this->experienceType((string) ($data['event_type'] ?? 'perigallo_experience'));
        $originApp = $this->originApp((string) ($data['origin_app'] ?? 'perigallo_web'));
        $title = clean_string((string) ($data['title'] ?? 'Nuevo evento'), 190) ?: 'Nuevo evento';
        $slug = $this->uniqueSlug(clean_string((string) ($data['slug'] ?? $title), 140));
        $startsAt = $this->dateValue((string) ($data['starts_at'] ?? ''), (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s'));
        $saleStartsAt = $this->dateValue((string) ($data['sale_starts_at'] ?? ''), (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
        $saleEndsAt = $this->dateValue((string) ($data['sale_ends_at'] ?? ''), $startsAt);
        $stmt = $this->pdo->prepare(
            'INSERT INTO events
             (canonical_id, event_type, origin_app, source_updated_at, slug, title, subtitle, description, image_url, location, address, starts_at, ends_at, sale_starts_at, sale_ends_at, capacity, status, visible, promoter, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $canonicalId,
            $eventType,
            $originApp,
            $slug,
            $title,
            clean_string((string) ($data['subtitle'] ?? ''), 190),
            trim((string) ($data['description'] ?? '')),
            clean_string((string) ($data['image_url'] ?? ''), 500),
            clean_string((string) ($data['location'] ?? 'Finca La Llaguna'), 190),
            clean_string((string) ($data['address'] ?? ''), 255),
            $startsAt,
            $this->dateValue((string) ($data['ends_at'] ?? ''), $startsAt),
            $saleStartsAt,
            $saleEndsAt,
            max(0, (int) ($data['capacity'] ?? 0)),
            'draft',
            0,
            clean_string((string) ($data['promoter'] ?? 'JYD Events, S.L.'), 190),
        ]);
        return $this->adminGetEvent((int) $this->pdo->lastInsertId()) ?? [];
    }

    public function adminCreateTicketType(int $eventId, array $data): array
    {
        $this->requireAdminEvent($eventId);
        $this->validateTicketType($data);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_types
             (event_id, name, description, price_cents, capacity, min_quantity, max_per_order, active, sort_order, tax_rate, fee_cents, sale_starts_at, sale_ends_at, status, visible, requires_promo, promo_code_hash, waitlist_enabled, refundable, terms_text, label_color, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $eventId,
            clean_string((string) $data['name'], 160),
            trim((string) ($data['description'] ?? '')),
            (int) $data['price_cents'],
            (int) $data['capacity'],
            max(1, (int) ($data['min_quantity'] ?? 1)),
            max(1, (int) ($data['max_per_order'] ?? 10)),
            $this->ticketActive($data) ? 1 : 0,
            (int) ($data['sort_order'] ?? 100),
            max(0, (float) ($data['tax_rate'] ?? 0)),
            max(0, (int) ($data['fee_cents'] ?? 0)),
            $this->dateValue((string) ($data['sale_starts_at'] ?? ''), now_mysql()),
            $this->dateValue((string) ($data['sale_ends_at'] ?? ''), '2036-12-31 23:59:59'),
            $this->ticketStatus($data),
            !empty($data['visible']) ? 1 : 0,
            !empty($data['requires_promo']) ? 1 : 0,
            $this->promoCodeHash($data),
            !empty($data['waitlist_enabled']) ? 1 : 0,
            !empty($data['refundable']) ? 1 : 0,
            trim((string) ($data['terms_text'] ?? '')),
            clean_string((string) ($data['label_color'] ?? ''), 24),
        ]);
        return $this->adminTicketType((int) $this->pdo->lastInsertId()) ?? [];
    }

    public function adminListEvents(): array
    {
        $rows = $this->pdo->query('SELECT * FROM events ORDER BY starts_at DESC, id DESC LIMIT 100')->fetchAll();
        return array_map(fn (array $event) => $this->adminEvent($event), $rows);
    }

    /** Datos internos para Suite. Perigallo.com conserva la fuente de verdad y el stock transaccional. */
    public function integrationListExperiences(?string $eventType = null): array
    {
        $sql = 'SELECT * FROM events';
        $params = [];
        if ($eventType !== null) {
            $sql .= ' WHERE event_type = ?';
            $params[] = $this->experienceType($eventType);
        }
        $sql .= ' ORDER BY starts_at DESC, id DESC LIMIT 200';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(fn (array $event) => $this->adminEvent($event), $stmt->fetchAll());
    }

    public function integrationGetExperience(string $canonicalId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE canonical_id = ? LIMIT 1');
        $stmt->execute([$this->canonicalId($canonicalId)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->adminGetEvent((int) $id);
    }

    public function integrationCreateExperience(array $data, string $sourceApp, string $idempotencyKey = ''): array
    {
        if ($previous = $this->integrationEventFromIdempotency($idempotencyKey)) {
            return $previous;
        }
        $data['origin_app'] = $this->originApp($sourceApp);
        $data['event_type'] = $this->experienceType((string) ($data['event_type'] ?? 'perigallo_experience'));
        $event = $this->adminCreateEvent($data);
        $this->integrationLog((string) $event['canonical_id'], $sourceApp, 'create', $idempotencyKey, 'success', '', (int) $event['id']);
        return $event;
    }

    public function integrationUpdateExperience(string $canonicalId, array $data, string $sourceApp, string $idempotencyKey = ''): array
    {
        $event = $this->integrationGetExperience($canonicalId);
        if (!$event) {
            throw new InvalidArgumentException('Experiencia no encontrada.');
        }
        $expected = trim((string) ($data['expected_updated_at'] ?? ''));
        if ($expected !== '' && (string) ($event['updated_at'] ?? '') !== $expected) {
            $this->integrationLog((string) $event['canonical_id'], $sourceApp, 'update', $idempotencyKey, 'failed', 'El registro fue modificado desde otra aplicación.');
            throw new RuntimeException('La experiencia ha cambiado en Perigallo.com. Recarga antes de guardar.', 409);
        }
        unset($data['canonical_id'], $data['id'], $data['expected_updated_at']);
        $data['origin_app'] = $this->originApp($sourceApp);
        $data['event_type'] = $this->experienceType((string) ($data['event_type'] ?? $event['event_type'] ?? 'perigallo_experience'));
        $result = $this->adminUpdateEvent((int) $event['id'], $data);
        $this->integrationLog((string) $result['canonical_id'], $sourceApp, 'update', $idempotencyKey, 'success');
        return $result;
    }

    public function integrationSetPublication(string $canonicalId, bool $publish, string $sourceApp, string $idempotencyKey = ''): array
    {
        $event = $this->integrationGetExperience($canonicalId);
        if (!$event) {
            throw new InvalidArgumentException('Experiencia no encontrada.');
        }
        $result = $this->adminSetEventPublication((int) $event['id'], $publish);
        $this->integrationLog((string) $result['canonical_id'], $sourceApp, $publish ? 'publish' : 'unpublish', $idempotencyKey, 'success');
        return $result;
    }

    public function integrationCreateTicketType(string $canonicalId, array $data, string $sourceApp, string $idempotencyKey = ''): array
    {
        if ($previous = $this->integrationTicketFromIdempotency($idempotencyKey)) {
            return $previous;
        }
        $event = $this->integrationGetExperience($canonicalId);
        if (!$event) {
            throw new InvalidArgumentException('Experiencia no encontrada.');
        }
        $ticket = $this->adminCreateTicketType((int) $event['id'], $data);
        $this->integrationLog((string) $event['canonical_id'], $sourceApp, 'ticket_type_create', $idempotencyKey, 'success', '', (int) $event['id'], (int) $ticket['id']);
        return $ticket;
    }

    public function integrationUpdateTicketType(string $canonicalId, int $ticketTypeId, array $data, string $sourceApp, string $idempotencyKey = ''): array
    {
        $event = $this->integrationGetExperience($canonicalId);
        if (!$event) {
            throw new InvalidArgumentException('Experiencia no encontrada.');
        }
        $ticket = $this->adminUpdateTicketType((int) $event['id'], $ticketTypeId, $data);
        $this->integrationLog((string) $event['canonical_id'], $sourceApp, 'ticket_type_update', $idempotencyKey, 'success');
        return $ticket;
    }

    public function integrationArchiveTicketType(string $canonicalId, int $ticketTypeId, string $sourceApp, string $idempotencyKey = ''): array
    {
        $event = $this->integrationGetExperience($canonicalId);
        if (!$event) {
            throw new InvalidArgumentException('Experiencia no encontrada.');
        }
        $result = $this->adminArchiveOrDeleteTicketType((int) $event['id'], $ticketTypeId);
        $this->integrationLog((string) $event['canonical_id'], $sourceApp, 'ticket_type_archive', $idempotencyKey, 'success');
        return $result;
    }

    public function integrationSalesSummary(string $canonicalId): array
    {
        $event = $this->integrationGetExperience($canonicalId);
        if (!$event) throw new InvalidArgumentException('Experiencia no encontrada.');
        $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT tor.id) AS orders, COALESCE(SUM(CASE WHEN tor.status="paid" THEN 1 ELSE 0 END), 0) AS paid_orders, COALESCE(SUM(CASE WHEN t.status="used" THEN 1 ELSE 0 END), 0) AS checked_in FROM ticket_orders tor JOIN ticket_order_items toi ON toi.order_id=tor.id LEFT JOIN tickets t ON t.order_item_id=toi.id WHERE toi.event_id=?');
        $stmt->execute([(int) $event['id']]);
        $summary = $stmt->fetch() ?: [];
        return [
            'orders' => (int) ($summary['orders'] ?? 0),
            'paid_orders' => (int) ($summary['paid_orders'] ?? 0),
            'checked_in' => (int) ($summary['checked_in'] ?? 0),
            'sold' => (int) $event['sold'], 'reserved' => (int) $event['reserved'],
            'available' => (int) $event['available'], 'revenue_cents' => (int) $event['revenue_cents'],
        ];
    }

    public function integrationOrders(string $canonicalId): array
    {
        $event = $this->integrationGetExperience($canonicalId);
        if (!$event) throw new InvalidArgumentException('Experiencia no encontrada.');
        $stmt = $this->pdo->prepare(
            'SELECT tor.id, tor.name, tor.email, tor.phone, tor.total_cents, tor.status, tor.paid_at, tor.created_at,
                    COALESCE(SUM(toi.quantity), 0) AS quantity, COALESCE(SUM(CASE WHEN t.status="used" THEN 1 ELSE 0 END), 0) AS checked_in,
                    GROUP_CONCAT(CONCAT(toi.ticket_type_name, " ×", toi.quantity) ORDER BY toi.id SEPARATOR " · ") AS ticket_types
             FROM ticket_orders tor JOIN ticket_order_items toi ON toi.order_id=tor.id LEFT JOIN tickets t ON t.order_item_id=toi.id
             WHERE toi.event_id=? GROUP BY tor.id ORDER BY tor.created_at DESC LIMIT 200'
        );
        $stmt->execute([(int) $event['id']]);
        return array_map(static fn (array $row) => [
            'id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'phone' => $row['phone'],
            'total_cents' => (int) $row['total_cents'], 'status' => $row['status'], 'paid_at' => $row['paid_at'], 'created_at' => $row['created_at'],
            'quantity' => (int) $row['quantity'], 'checked_in' => (int) $row['checked_in'], 'ticket_types' => $row['ticket_types'] ?? '',
        ], $stmt->fetchAll());
    }

    public function adminGetEvent(int $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        if (!$event) {
            return null;
        }
        $result = $this->adminEvent($event);
        $result['ticket_types'] = $this->adminTicketTypesForEvent($eventId);
        return $result;
    }

    public function adminUpdateEvent(int $eventId, array $data): array
    {
        $existing = $this->requireAdminEvent($eventId);
        $merged = array_merge($existing, $data);
        $status = $this->eventStatus($merged);
        $visible = !empty($merged['visible']) ? 1 : 0;
        if ($status === 'published') {
            $this->validateEventForPublication($merged);
            $visible = 1;
        }
        if ($status === 'scheduled') {
            $this->validateEventForPublication($merged);
            if (empty($merged['publication_at']) || strtotime((string) $merged['publication_at']) === false) {
                throw new RuntimeException('Indica una fecha de publicación para programar el evento.');
            }
            $visible = 1;
        }
        $slug = $this->uniqueSlug(clean_string((string) ($merged['slug'] ?? ''), 140), $eventId);
        $startsAt = $this->dateValue((string) ($merged['starts_at'] ?? ''), (string) $existing['starts_at']);
        $endsAt = $this->dateValue((string) ($merged['ends_at'] ?? ''), $startsAt);
        $saleStartsAt = $this->dateValue((string) ($merged['sale_starts_at'] ?? ''), (string) $existing['sale_starts_at']);
        $saleEndsAt = $this->dateValue((string) ($merged['sale_ends_at'] ?? ''), (string) $existing['sale_ends_at']);
        if (strtotime($endsAt) < strtotime($startsAt)) {
            throw new RuntimeException('La fecha de finalizacion debe ser posterior al inicio.');
        }
        if (strtotime($saleEndsAt) < strtotime($saleStartsAt)) {
            throw new RuntimeException('El cierre de venta debe ser posterior al inicio de venta.');
        }
        $capacity = max(0, (int) ($merged['capacity'] ?? 0));
        if ($capacity < $this->eventCommittedQuantity($eventId)) {
            throw new RuntimeException('No puedes reducir el aforo por debajo de las entradas ya vendidas o reservadas.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE events SET
              slug=?, title=?, subtitle=?, short_description=?, description=?, category=?, event_type=?, origin_app=?, source_updated_at=NOW(), tags=?, locale=?,
              image_url=?, card_image_url=?, social_image_url=?, gallery_json=?, video_url=?, logo_url=?,
              location=?, address=?, postal_code=?, locality=?, province=?, country=?, maps_url=?, access_notes=?, parking_info=?, venue_type=?,
              starts_at=?, ends_at=?, doors_open_at=?, timezone=?, schedule_note=?, sale_starts_at=?, sale_ends_at=?, capacity=?,
              included_text=?, access_conditions=?, minor_policy=?, refund_policy=?, faq_json=?, contact_info=?, recommendations=?, dress_code=?, accessibility_info=?,
              status=?, visible=?, promoter=?, publication_at=?, unlisted=?, link_only=?, show_sold_out=?, show_availability=?, show_price_from=?,
              seo_title=?, seo_description=?, seo_image_url=?, canonical_url=?, updated_at=NOW()
              WHERE id=?'
        );
        $stmt->execute([
            $slug,
            clean_string((string) ($merged['title'] ?? ''), 190),
            clean_string((string) ($merged['subtitle'] ?? ''), 190),
            trim((string) ($merged['short_description'] ?? '')),
            trim((string) ($merged['description'] ?? '')),
            clean_string((string) ($merged['category'] ?? ''), 100),
            $this->experienceType((string) ($merged['event_type'] ?? 'perigallo_experience')),
            $this->originApp((string) ($merged['origin_app'] ?? 'perigallo_web')),
            clean_string((string) ($merged['tags'] ?? ''), 500),
            clean_string((string) ($merged['locale'] ?? 'es'), 12) ?: 'es',
            clean_string((string) ($merged['image_url'] ?? ''), 500),
            clean_string((string) ($merged['card_image_url'] ?? ''), 500),
            clean_string((string) ($merged['social_image_url'] ?? ''), 500),
            $this->jsonArray($merged['gallery'] ?? $merged['gallery_json'] ?? []),
            clean_string((string) ($merged['video_url'] ?? ''), 500),
            clean_string((string) ($merged['logo_url'] ?? ''), 500),
            clean_string((string) ($merged['location'] ?? ''), 190),
            clean_string((string) ($merged['address'] ?? ''), 255),
            clean_string((string) ($merged['postal_code'] ?? ''), 24),
            clean_string((string) ($merged['locality'] ?? ''), 120),
            clean_string((string) ($merged['province'] ?? ''), 120),
            clean_string((string) ($merged['country'] ?? 'España'), 120) ?: 'España',
            clean_string((string) ($merged['maps_url'] ?? ''), 500),
            trim((string) ($merged['access_notes'] ?? '')),
            trim((string) ($merged['parking_info'] ?? '')),
            in_array((string) ($merged['venue_type'] ?? 'in_person'), ['in_person', 'online', 'hybrid'], true) ? $merged['venue_type'] : 'in_person',
            $startsAt,
            $endsAt,
            $this->nullableDate((string) ($merged['doors_open_at'] ?? '')),
            clean_string((string) ($merged['timezone'] ?? 'Europe/Madrid'), 64) ?: 'Europe/Madrid',
            clean_string((string) ($merged['schedule_note'] ?? ''), 500),
            $saleStartsAt,
            $saleEndsAt,
            $capacity,
            trim((string) ($merged['included_text'] ?? '')),
            trim((string) ($merged['access_conditions'] ?? '')),
            trim((string) ($merged['minor_policy'] ?? '')),
            trim((string) ($merged['refund_policy'] ?? '')),
            $this->jsonArray($merged['faq'] ?? $merged['faq_json'] ?? []),
            trim((string) ($merged['contact_info'] ?? '')),
            trim((string) ($merged['recommendations'] ?? '')),
            clean_string((string) ($merged['dress_code'] ?? ''), 255),
            trim((string) ($merged['accessibility_info'] ?? '')),
            $status,
            $visible,
            clean_string((string) ($merged['promoter'] ?? 'JYD Events, S.L.'), 190) ?: 'JYD Events, S.L.',
            $this->nullableDate((string) ($merged['publication_at'] ?? '')),
            !empty($merged['unlisted']) ? 1 : 0,
            !empty($merged['link_only']) ? 1 : 0,
            array_key_exists('show_sold_out', $merged) && empty($merged['show_sold_out']) ? 0 : 1,
            array_key_exists('show_availability', $merged) && empty($merged['show_availability']) ? 0 : 1,
            array_key_exists('show_price_from', $merged) && empty($merged['show_price_from']) ? 0 : 1,
            clean_string((string) ($merged['seo_title'] ?? ''), 190),
            clean_string((string) ($merged['seo_description'] ?? ''), 320),
            clean_string((string) ($merged['seo_image_url'] ?? ''), 500),
            clean_string((string) ($merged['canonical_url'] ?? ''), 500),
            $eventId,
        ]);
        return $this->adminGetEvent($eventId) ?? [];
    }

    public function adminSetEventPublication(int $eventId, bool $publish): array
    {
        $event = $this->requireAdminEvent($eventId);
        if ($publish) {
            $this->validateEventForPublication($event);
            $this->pdo->prepare('UPDATE events SET status="published", visible=1, publication_at=COALESCE(publication_at, NOW()), updated_at=NOW() WHERE id=?')->execute([$eventId]);
        } else {
            $this->pdo->prepare('UPDATE events SET status="draft", visible=0, updated_at=NOW() WHERE id=?')->execute([$eventId]);
        }
        return $this->adminGetEvent($eventId) ?? [];
    }

    public function adminDuplicateEvent(int $eventId): array
    {
        $event = $this->requireAdminEvent($eventId);
        $copy = $event;
        $copy['title'] = 'Copia de ' . $event['title'];
        $copy['slug'] = $this->uniqueSlug($event['slug'] . '-copia');
        $copy['status'] = 'draft';
        $copy['visible'] = false;
        $copy['publication_at'] = null;
        unset($copy['canonical_id']);
        $new = $this->adminCreateEvent($copy);
        $this->adminUpdateEvent((int) $new['id'], $copy);
        $types = $this->adminTicketTypesForEvent($eventId);
        foreach ($types as $type) {
            unset($type['id'], $type['sold'], $type['reserved'], $type['available'], $type['final_price_cents']);
            $type['name'] = $type['name'] . ' (copia)';
            // Por seguridad no se replica un código que solo se almacena cifrado.
            $type['requires_promo'] = false;
            $this->adminCreateTicketType((int) $new['id'], $type);
        }
        return $this->adminGetEvent((int) $new['id']) ?? [];
    }

    public function adminArchiveOrDeleteEvent(int $eventId): array
    {
        $this->requireAdminEvent($eventId);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ticket_order_items WHERE event_id=?');
        $stmt->execute([$eventId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $this->pdo->prepare('UPDATE events SET status="archived", visible=0, updated_at=NOW() WHERE id=?')->execute([$eventId]);
            return ['deleted' => false, 'archived' => true];
        }
        $this->pdo->prepare('DELETE FROM ticket_types WHERE event_id=?')->execute([$eventId]);
        $this->pdo->prepare('DELETE FROM events WHERE id=?')->execute([$eventId]);
        return ['deleted' => true, 'archived' => false];
    }

    public function adminUpdateTicketType(int $eventId, int $ticketTypeId, array $data): array
    {
        $this->requireAdminEvent($eventId);
        $existing = $this->requireTicketType($eventId, $ticketTypeId);
        $merged = array_merge($existing, $data);
        $this->validateTicketType($merged);
        $capacity = max(0, (int) $merged['capacity']);
        if ($capacity < $this->ticketCommittedQuantity($ticketTypeId)) {
            throw new RuntimeException('No puedes reducir el cupo por debajo de las entradas vendidas o reservadas.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ticket_types SET name=?, description=?, price_cents=?, capacity=?, min_quantity=?, max_per_order=?, active=?, sort_order=?, tax_rate=?, fee_cents=?, sale_starts_at=?, sale_ends_at=?, status=?, visible=?, requires_promo=?, promo_code_hash=?, waitlist_enabled=?, refundable=?, terms_text=?, label_color=?, archived_at=?, updated_at=NOW() WHERE id=? AND event_id=?'
        );
        $status = $this->ticketStatus($merged);
        $stmt->execute([
            clean_string((string) $merged['name'], 160), trim((string) ($merged['description'] ?? '')), max(0, (int) $merged['price_cents']), $capacity,
            max(1, (int) $merged['min_quantity']), max(1, (int) $merged['max_per_order']), $this->ticketActive($merged) ? 1 : 0,
            (int) ($merged['sort_order'] ?? 100), max(0, (float) ($merged['tax_rate'] ?? 0)), max(0, (int) ($merged['fee_cents'] ?? 0)),
            $this->dateValue((string) ($merged['sale_starts_at'] ?? ''), now_mysql()), $this->dateValue((string) ($merged['sale_ends_at'] ?? ''), '2036-12-31 23:59:59'),
            $status, !empty($merged['visible']) ? 1 : 0, !empty($merged['requires_promo']) ? 1 : 0, $this->promoCodeHash($merged, (string) ($existing['promo_code_hash'] ?? '')), !empty($merged['waitlist_enabled']) ? 1 : 0,
            !empty($merged['refundable']) ? 1 : 0, trim((string) ($merged['terms_text'] ?? '')), clean_string((string) ($merged['label_color'] ?? ''), 24),
            $status === 'archived' ? now_mysql() : null, $ticketTypeId, $eventId,
        ]);
        return $this->adminTicketType($ticketTypeId) ?? [];
    }

    public function adminReorderTicketTypes(int $eventId, array $ids): array
    {
        $this->requireAdminEvent($eventId);
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE ticket_types SET sort_order=?, updated_at=NOW() WHERE id=? AND event_id=?');
            foreach (array_values($ids) as $index => $id) {
                $update->execute([($index + 1) * 10, (int) $id, $eventId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->adminTicketTypesForEvent($eventId);
    }

    public function adminArchiveOrDeleteTicketType(int $eventId, int $ticketTypeId): array
    {
        $this->requireTicketType($eventId, $ticketTypeId);
        if ($this->ticketCommittedQuantity($ticketTypeId) > 0) {
            $this->pdo->prepare('UPDATE ticket_types SET active=0, visible=0, status="archived", archived_at=NOW(), updated_at=NOW() WHERE id=?')->execute([$ticketTypeId]);
            return ['deleted' => false, 'archived' => true];
        }
        $this->pdo->prepare('DELETE FROM ticket_types WHERE id=? AND event_id=?')->execute([$ticketTypeId, $eventId]);
        return ['deleted' => true, 'archived' => false];
    }

    public function adminDuplicateTicketType(int $eventId, int $ticketTypeId): array
    {
        $type = $this->requireTicketType($eventId, $ticketTypeId);
        $copy = $type;
        unset($copy['id'], $copy['sold'], $copy['reserved'], $copy['available'], $copy['final_price_cents'], $copy['effective_status']);
        $copy['name'] = clean_string('Copia de ' . $type['name'], 160);
        $copy['status'] = 'draft';
        $copy['active'] = false;
        $copy['visible'] = false;
        // El código original no se puede recuperar desde su hash: se define uno nuevo al editar la copia.
        $copy['requires_promo'] = false;
        $copy['sort_order'] = (int) $type['sort_order'] + 5;
        return $this->adminCreateTicketType($eventId, $copy);
    }

    public function adminUploadImage(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo recibir la imagen.');
        }
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('La imagen debe pesar menos de 5 MB.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'];
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Formato no permitido. Usa JPG, PNG, WebP o AVIF.');
        }
        $directory = dirname(__DIR__, 2) . '/assets/uploads/events';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el directorio de imagenes.');
        }
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        $target = $directory . '/' . $name;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }
        return ['url' => '/assets/uploads/events/' . $name];
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

    private function adminEvent(array $event): array
    {
        $metrics = $this->eventMetrics((int) $event['id']);
        $event['id'] = (int) $event['id'];
        $event['capacity'] = (int) $event['capacity'];
        $event['visible'] = (bool) $event['visible'];
        foreach (['unlisted', 'link_only', 'show_sold_out', 'show_availability', 'show_price_from'] as $field) {
            $event[$field] = array_key_exists($field, $event) ? (bool) $event[$field] : false;
        }
        $event['gallery'] = $this->decodedArray($event['gallery_json'] ?? null);
        $event['faq'] = $this->decodedArray($event['faq_json'] ?? null);
        $event['sold'] = $metrics['sold'];
        $event['reserved'] = $metrics['reserved'];
        $event['available'] = max(0, $event['capacity'] - $metrics['sold'] - $metrics['reserved']);
        $event['revenue_cents'] = $metrics['revenue_cents'];
        $event['effective_status'] = $this->eventEffectiveStatus($event);
        return $event;
    }

    private function canonicalId(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value !== '') {
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
                throw new InvalidArgumentException('El identificador canónico de la experiencia no es válido.');
            }
            return $value;
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function experienceType(string $value): string
    {
        return in_array($value, ['restaurant_popup', 'perigallo_experience'], true) ? $value : 'perigallo_experience';
    }

    private function originApp(string $value): string
    {
        return in_array($value, ['suite', 'perigallo_web'], true) ? $value : 'perigallo_web';
    }

    private function integrationEventFromIdempotency(string $idempotencyKey): ?array
    {
        if ($idempotencyKey === '') return null;
        $stmt = $this->pdo->prepare('SELECT event_id FROM experience_sync_logs WHERE idempotency_key = ? AND status = "success" LIMIT 1');
        $stmt->execute([$idempotencyKey]);
        $eventId = (int) ($stmt->fetchColumn() ?: 0);
        return $eventId > 0 ? $this->adminGetEvent($eventId) : null;
    }

    private function integrationTicketFromIdempotency(string $idempotencyKey): ?array
    {
        if ($idempotencyKey === '') return null;
        $stmt = $this->pdo->prepare('SELECT ticket_type_id FROM experience_sync_logs WHERE idempotency_key = ? AND status = "success" LIMIT 1');
        $stmt->execute([$idempotencyKey]);
        $ticketTypeId = (int) ($stmt->fetchColumn() ?: 0);
        return $ticketTypeId > 0 ? $this->adminTicketType($ticketTypeId) : null;
    }

    private function integrationLog(string $canonicalId, string $sourceApp, string $action, string $idempotencyKey, string $status, string $error = '', int $eventId = 0, int $ticketTypeId = 0): void
    {
        try {
            $key = clean_string($idempotencyKey, 191) ?: bin2hex(random_bytes(16));
            $stmt = $this->pdo->prepare(
                'INSERT INTO experience_sync_logs (canonical_id, event_id, ticket_type_id, source_app, destination_app, action, status, idempotency_key, attempts, error_message, completed_at)
                 VALUES (?, ?, ?, ?, "perigallo_web", ?, ?, ?, 1, ?, NOW())
                 ON DUPLICATE KEY UPDATE attempts=attempts+1, status=VALUES(status), error_message=VALUES(error_message), completed_at=NOW()'
            );
            $stmt->execute([$canonicalId, $eventId ?: null, $ticketTypeId ?: null, clean_string($sourceApp, 32), clean_string($action, 64), $status, $key, clean_string($error, 1000)]);
        } catch (\Throwable $e) {
            error_log('Experience integration log error: ' . $e->getMessage());
        }
    }

    private function adminTicketTypesForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE event_id=? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$eventId]);
        return array_map(fn (array $row) => $this->adminTicketTypeRow($row), $stmt->fetchAll());
    }

    private function adminTicketType(int $ticketTypeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE id=? LIMIT 1');
        $stmt->execute([$ticketTypeId]);
        $row = $stmt->fetch();
        return $row ? $this->adminTicketTypeRow($row) : null;
    }

    private function adminTicketTypeRow(array $type): array
    {
        $committed = $this->ticketMetrics((int) $type['id']);
        $type['id'] = (int) $type['id'];
        $type['event_id'] = (int) $type['event_id'];
        $type['price_cents'] = (int) $type['price_cents'];
        $type['fee_cents'] = (int) ($type['fee_cents'] ?? 0);
        $type['tax_rate'] = (float) ($type['tax_rate'] ?? 0);
        $type['capacity'] = (int) $type['capacity'];
        $type['min_quantity'] = (int) $type['min_quantity'];
        $type['max_per_order'] = (int) $type['max_per_order'];
        $type['sort_order'] = (int) $type['sort_order'];
        foreach (['active', 'visible', 'requires_promo', 'waitlist_enabled', 'refundable'] as $field) {
            $type[$field] = (bool) ($type[$field] ?? false);
        }
        $type['has_promo_code'] = !empty($type['promo_code_hash']);
        unset($type['promo_code_hash']);
        $type['sold'] = $committed['sold'];
        $type['reserved'] = $committed['reserved'];
        $type['available'] = max(0, $type['capacity'] - $committed['sold'] - $committed['reserved']);
        $type['final_price_cents'] = $type['price_cents'] + (int) round($type['price_cents'] * $type['tax_rate'] / 100) + $type['fee_cents'];
        $type['effective_status'] = $this->ticketEffectiveStatus($type, $type['available']);
        return $type;
    }

    private function eventMetrics(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
              COALESCE(SUM(CASE WHEN tor.status="paid" THEN toi.quantity ELSE 0 END), 0) AS sold,
              COALESCE(SUM(CASE WHEN tor.status IN ("pending","payment_processing") AND tor.reservation_expires_at > NOW() THEN toi.quantity ELSE 0 END), 0) AS reserved,
              COALESCE(SUM(CASE WHEN tor.status="paid" THEN toi.total_cents ELSE 0 END), 0) AS revenue_cents
             FROM ticket_order_items toi JOIN ticket_orders tor ON tor.id=toi.order_id WHERE toi.event_id=?'
        );
        $stmt->execute([$eventId]);
        $row = $stmt->fetch() ?: [];
        return ['sold' => (int) ($row['sold'] ?? 0), 'reserved' => (int) ($row['reserved'] ?? 0), 'revenue_cents' => (int) ($row['revenue_cents'] ?? 0)];
    }

    private function ticketMetrics(int $ticketTypeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
              COALESCE(SUM(CASE WHEN tor.status="paid" THEN toi.quantity ELSE 0 END), 0) AS sold,
              COALESCE(SUM(CASE WHEN tor.status IN ("pending","payment_processing") AND tor.reservation_expires_at > NOW() THEN toi.quantity ELSE 0 END), 0) AS reserved
             FROM ticket_order_items toi JOIN ticket_orders tor ON tor.id=toi.order_id WHERE toi.ticket_type_id=?'
        );
        $stmt->execute([$ticketTypeId]);
        $row = $stmt->fetch() ?: [];
        return ['sold' => (int) ($row['sold'] ?? 0), 'reserved' => (int) ($row['reserved'] ?? 0)];
    }

    private function eventCommittedQuantity(int $eventId): int
    {
        $metrics = $this->eventMetrics($eventId);
        return $metrics['sold'] + $metrics['reserved'];
    }

    private function ticketCommittedQuantity(int $ticketTypeId): int
    {
        $metrics = $this->ticketMetrics($ticketTypeId);
        return $metrics['sold'] + $metrics['reserved'];
    }

    private function requireAdminEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id=? LIMIT 1');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        if (!$event) {
            throw new RuntimeException('Evento no encontrado.');
        }
        return $event;
    }

    private function requireTicketType(int $eventId, int $ticketTypeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE id=? AND event_id=? LIMIT 1');
        $stmt->execute([$ticketTypeId, $eventId]);
        $type = $stmt->fetch();
        if (!$type) {
            throw new RuntimeException('Tipo de entrada no encontrado.');
        }
        return $type;
    }

    private function validateEventForPublication(array $event): void
    {
        foreach (['title' => 'nombre', 'description' => 'descripcion', 'location' => 'ubicacion', 'starts_at' => 'fecha de inicio'] as $field => $label) {
            if (trim((string) ($event[$field] ?? '')) === '') {
                throw new RuntimeException('Falta ' . $label . ' para publicar el evento.');
            }
        }
        if ((int) ($event['capacity'] ?? 0) < 1) {
            throw new RuntimeException('Indica un aforo valido antes de publicar.');
        }
        $types = $this->adminTicketTypesForEvent((int) ($event['id'] ?? 0));
        $sellable = array_filter($types, fn (array $type) => $type['visible'] && in_array($type['status'], ['upcoming', 'on_sale'], true));
        if (empty($sellable)) {
            throw new RuntimeException('Crea al menos un tipo de entrada visible y vendible antes de publicar.');
        }
    }

    private function validateTicketType(array $data): void
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new RuntimeException('El nombre de la entrada es obligatorio.');
        }
        if ((int) ($data['price_cents'] ?? -1) < 0 || (int) ($data['capacity'] ?? -1) < 0) {
            throw new RuntimeException('Precio y cupo deben ser validos.');
        }
        $min = max(1, (int) ($data['min_quantity'] ?? 1));
        $max = max(1, (int) ($data['max_per_order'] ?? 1));
        if ($max < $min) {
            throw new RuntimeException('El maximo por pedido debe ser igual o mayor que el minimo.');
        }
    }

    private function uniqueSlug(string $value, ?int $excludeId = null): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '', '-'));
        $slug = substr($slug ?: 'evento', 0, 130);
        $base = $slug;
        $number = 2;
        while (true) {
            $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug=?' . ($excludeId ? ' AND id<>?' : '') . ' LIMIT 1');
            $stmt->execute($excludeId ? [$slug, $excludeId] : [$slug]);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $suffix = '-' . $number++;
            $slug = substr($base, 0, 140 - strlen($suffix)) . $suffix;
        }
    }

    private function eventStatus(array $event): string
    {
        $status = (string) ($event['status'] ?? 'draft');
        return in_array($status, ['draft', 'scheduled', 'published', 'sold_out', 'finished', 'cancelled', 'archived'], true) ? $status : 'draft';
    }

    private function ticketStatus(array $type): string
    {
        $status = (string) ($type['status'] ?? 'on_sale');
        return in_array($status, ['draft', 'upcoming', 'on_sale', 'paused', 'sold_out', 'closed', 'hidden', 'archived'], true) ? $status : 'on_sale';
    }

    private function ticketActive(array $type): bool
    {
        $enabled = array_key_exists('active', $type) ? !empty($type['active']) : true;
        return $enabled && !in_array($this->ticketStatus($type), ['draft', 'paused', 'closed', 'hidden', 'archived'], true);
    }

    private function eventEffectiveStatus(array $event): string
    {
        if ((string) $event['status'] === 'scheduled' && !empty($event['publication_at']) && strtotime((string) $event['publication_at']) <= time()) {
            return (int) ($event['available'] ?? 0) <= 0 ? 'sold_out' : 'published';
        }
        if (in_array((string) $event['status'], ['cancelled', 'archived', 'finished', 'draft', 'scheduled'], true)) {
            return (string) $event['status'];
        }
        return (int) ($event['available'] ?? 0) <= 0 ? 'sold_out' : (string) $event['status'];
    }

    private function ticketEffectiveStatus(array $type, int $available): string
    {
        $status = (string) ($type['status'] ?? 'on_sale');
        if (in_array($status, ['draft', 'paused', 'closed', 'hidden', 'archived'], true)) {
            return $status;
        }
        if ($available <= 0) {
            return 'sold_out';
        }
        $now = time();
        if (!empty($type['sale_starts_at']) && strtotime((string) $type['sale_starts_at']) > $now) {
            return 'upcoming';
        }
        if (!empty($type['sale_ends_at']) && strtotime((string) $type['sale_ends_at']) < $now) {
            return 'closed';
        }
        return 'on_sale';
    }

    private function jsonArray(mixed $value): ?string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $value) ?: [])));
            }
        }
        return is_array($value) && $value !== [] ? json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    private function decodedArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function dateValue(string $value, string $fallback): string
    {
        $timestamp = strtotime(str_replace('T', ' ', trim($value)));
        return $timestamp === false ? $fallback : date('Y-m-d H:i:s', $timestamp);
    }

    private function nullableDate(string $value): ?string
    {
        $timestamp = strtotime(str_replace('T', ' ', trim($value)));
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function ticketTypesForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE event_id = ? AND active = 1 AND visible = 1 AND status NOT IN ("hidden", "archived") ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $type) {
            $available = $this->availableForType((int) $type['id'], (int) $type['capacity']);
            $effectiveStatus = $this->ticketEffectiveStatus($type, $available);
            $rows[] = [
                'id' => (int) $type['id'],
                'name' => $type['name'],
                'description' => $type['description'],
                'price_cents' => (int) $type['price_cents'],
                'capacity' => (int) $type['capacity'],
                'available' => $available,
                'min_quantity' => (int) $type['min_quantity'],
                'max_per_order' => (int) $type['max_per_order'],
                'fee_cents' => (int) ($type['fee_cents'] ?? 0),
                'final_price_cents' => (int) $type['price_cents'] + (int) round((int) $type['price_cents'] * (float) ($type['tax_rate'] ?? 0) / 100) + (int) ($type['fee_cents'] ?? 0),
                'status' => $effectiveStatus,
                'sale_starts_at' => $type['sale_starts_at'] ?? null,
                'sale_ends_at' => $type['sale_ends_at'] ?? null,
                'waitlist_enabled' => !empty($type['waitlist_enabled']),
                'requires_promo' => !empty($type['requires_promo']),
                'refundable' => !empty($type['refundable']),
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
            'short_description' => $event['short_description'] ?? null,
            'description' => $event['description'],
            'image_url' => $event['image_url'],
            'card_image_url' => $event['card_image_url'] ?? null,
            'social_image_url' => $event['social_image_url'] ?? null,
            'gallery' => $this->decodedArray($event['gallery_json'] ?? null),
            'video_url' => $event['video_url'] ?? null,
            'location' => $event['location'],
            'address' => $event['address'],
            'maps_url' => $event['maps_url'] ?? null,
            'access_notes' => $event['access_notes'] ?? null,
            'parking_info' => $event['parking_info'] ?? null,
            'starts_at' => $event['starts_at'],
            'ends_at' => $event['ends_at'],
            'doors_open_at' => $event['doors_open_at'] ?? null,
            'schedule_note' => $event['schedule_note'] ?? null,
            'sale_ends_at' => $event['sale_ends_at'],
            'status' => $event['status'],
            'price_from_cents' => isset($event['price_from_cents']) ? (int) $event['price_from_cents'] : null,
            'promoter' => $event['promoter'],
            'included_text' => $event['included_text'] ?? null,
            'access_conditions' => $event['access_conditions'] ?? null,
            'minor_policy' => $event['minor_policy'] ?? null,
            'refund_policy' => $event['refund_policy'] ?? null,
            'faq' => $this->decodedArray($event['faq_json'] ?? null),
            'contact_info' => $event['contact_info'] ?? null,
            'recommendations' => $event['recommendations'] ?? null,
            'dress_code' => $event['dress_code'] ?? null,
            'accessibility_info' => $event['accessibility_info'] ?? null,
            'show_availability' => !empty($event['show_availability']),
            'show_price_from' => !empty($event['show_price_from']),
        ];
    }

    private function findEventForSale(string $slug): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM events
             WHERE slug = ? AND visible = 1
             AND (status = "published" OR (status = "scheduled" AND publication_at IS NOT NULL AND publication_at <= NOW()))
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

    private function lockTicketType(int $typeId, int $eventId, string $promoCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE id = ? AND event_id = ? AND active = 1 AND visible = 1 AND status IN ("on_sale", "upcoming") AND sale_starts_at <= NOW() AND sale_ends_at >= NOW() FOR UPDATE');
        $stmt->execute([$typeId, $eventId]);
        $type = $stmt->fetch();
        if ($type && !empty($type['requires_promo']) && (trim($promoCode) === '' || empty($type['promo_code_hash']) || !password_verify($promoCode, (string) $type['promo_code_hash']))) {
            throw new RuntimeException('El código promocional no es válido para esta entrada.');
        }
        return $type ?: null;
    }

    private function promoCodeHash(array $data, string $existing = ''): ?string
    {
        if (empty($data['requires_promo'])) {
            return null;
        }
        $code = trim((string) ($data['promo_code'] ?? ''));
        if ($code === '') {
            if ($existing !== '') {
                return $existing;
            }
            throw new RuntimeException('Indica un código para la entrada restringida.');
        }
        if (mb_strlen($code) > 120) {
            throw new RuntimeException('El código promocional es demasiado largo.');
        }
        return password_hash($code, PASSWORD_DEFAULT);
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
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ticket_orders WHERE redsys_order = ?');
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
        $body = "Hola {$order['name']},\n\nTu pago se ha confirmado correctamente.\n\nPuedes consultar y descargar tus entradas aqui:\n{$link}\n\nContacto Perigallo: +34 691 499 985\n";
        $this->mailer->queueOrderEmail($this->pdo, $orderId, (string) $order['email'], 'Tus entradas Perigallo', $body);
    }
}
