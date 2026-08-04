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

            $this->redsys->assertConfigured();
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
                'payment' => $this->redsysForm($redsysOrder, $subtotal, $event, $publicToken),
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
            'SELECT t.id, t.public_code, t.qr_token_ciphertext, t.status, t.issued_at, t.used_at, e.title AS event_title, e.subtitle AS event_subtitle, e.starts_at, e.ends_at, e.doors_open_at, e.location, e.address, e.locality, e.province, e.dress_code, toi.ticket_type_name
             FROM tickets t
             JOIN events e ON e.id = t.event_id
             JOIN ticket_order_items toi ON toi.id = t.order_item_id
             WHERE toi.order_id = ?
             ORDER BY t.id ASC'
        );
        $tickets->execute([(int) $order['id']]);

        $ticketRows = $tickets->fetchAll();
        foreach ($ticketRows as &$ticket) {
            try {
                $ticket['qr_url'] = $this->ticketQrUrl($ticket);
            } catch (RuntimeException $error) {
                // El pedido sigue siendo consultable mientras se completa la clave de QR en produccion.
                $ticket['qr_url'] = null;
                $ticket['qr_error'] = 'El codigo QR se esta preparando.';
            }
            unset($ticket['qr_token_ciphertext']);
            unset($ticket['id']);
        }
        unset($ticket);

        $delivery = $this->pdo->prepare('SELECT channel, status, recipient, payload, created_at FROM ticket_delivery_logs WHERE order_id = ? ORDER BY id DESC');
        $delivery->execute([(int) $order['id']]);
        $emailDelivery = $this->pdo->prepare('SELECT status, created_at FROM email_deliveries WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $emailDelivery->execute([(int) $order['id']]);

        return [
            'token' => $order['public_token'],
            'status' => $this->effectiveOrderStatus($order),
            'is_test' => !empty($order['is_test']),
            'environment' => $order['environment'] ?? 'production',
            'reference' => $order['test_reference'] ?: $order['redsys_order'],
            'order_status' => $order['order_status'] ?? null,
            'payment_status' => $order['payment_status'] ?? null,
            'delivery_status' => $order['delivery_status'] ?? null,
            'name' => $order['name'],
            'email' => $order['email'],
            'phone' => $order['phone'],
            'total_cents' => (int) $order['total_cents'],
            'currency' => $order['currency'],
            'reservation_expires_at' => $order['reservation_expires_at'],
            'paid_at' => $order['paid_at'],
            'items' => $items->fetchAll(),
            'tickets' => $ticketRows,
            'deliveries' => $delivery->fetchAll(),
            'email_delivery' => $emailDelivery->fetch() ?: null,
        ];
    }

    /**
     * A recovery link is separate from the permanent order token so it can expire
     * or be revoked without invalidating historic URLs sent after a purchase.
     */
    public function getOrderByAccessLink(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,}$/', $token)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT l.id, o.public_token
             FROM ticket_order_access_links l
             JOIN ticket_orders o ON o.id = l.order_id
             WHERE l.token_hash = ? AND l.revoked_at IS NULL AND l.expires_at > NOW()
               AND (o.status = "paid" OR o.payment_status = "paid")
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $link = $stmt->fetch();
        if (!$link) {
            return null;
        }
        $this->pdo->prepare('UPDATE ticket_order_access_links SET access_count = access_count + 1, last_access_at = NOW() WHERE id = ?')
            ->execute([(int) $link['id']]);
        $order = $this->getOrderByToken((string) $link['public_token']);
        // El enlace de recuperación no revela ni convierte en permanente el token
        // histórico del pedido. Las acciones de reenvío se habilitarán mediante
        // endpoints específicos para enlaces temporales en una fase posterior.
        if ($order) {
            unset($order['token']);
        }
        return $order;
    }

    /** Always returns the same response to avoid revealing whether a purchase exists. */
    public function requestOrderAccessRecovery(array $data): array
    {
        $email = mb_strtolower(clean_string((string) ($data['email'] ?? ''), 190));
        $phone = preg_replace('/[^0-9+]/', '', clean_string((string) ($data['phone'] ?? ''), 60)) ?: '';
        $reference = strtoupper(clean_string((string) ($data['reference'] ?? ''), 64));
        if ($email === '' && $phone === '') {
            throw new InvalidArgumentException('Introduce el correo electrónico o teléfono usado en la compra.');
        }

        $identifierHash = hash('sha256', $email . '|' . $phone . '|' . $reference);
        $ipHash = hash_hmac('sha256', client_ip(), (string) env_value('APP_SECRET', 'perigallo-recovery-rate-limit'));
        $this->pdo->prepare('INSERT INTO ticket_access_recovery_requests (identifier_hash, ip_hash, requested_at) VALUES (?, ?, NOW())')
            ->execute([$identifierHash, $ipHash]);

        $identifierAttempts = $this->pdo->prepare('SELECT COUNT(*) FROM ticket_access_recovery_requests WHERE identifier_hash = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
        $identifierAttempts->execute([$identifierHash]);
        $ipAttempts = $this->pdo->prepare('SELECT COUNT(*) FROM ticket_access_recovery_requests WHERE ip_hash = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
        $ipAttempts->execute([$ipHash]);
        if ((int) $identifierAttempts->fetchColumn() > 3 || (int) $ipAttempts->fetchColumn() > 12) {
            return ['message' => 'Si encontramos una compra asociada a esos datos, recibirás un enlace para acceder a tus entradas.'];
        }

        $where = [];
        $params = [];
        if ($email !== '') {
            $where[] = 'email = ?';
            $params[] = $email;
        }
        if ($phone !== '') {
            $where[] = 'phone = ?';
            $params[] = $phone;
        }
        $match = '(' . implode(' OR ', $where) . ')';
        $sql = 'SELECT * FROM ticket_orders WHERE ' . $match . ' AND (status = "paid" OR payment_status = "paid")';
        if ($reference !== '') {
            $sql .= ' AND (redsys_order = ? OR test_reference = ?)';
            $params[] = $reference;
            $params[] = $reference;
        }
        $sql .= ' ORDER BY paid_at DESC, id DESC LIMIT 1';
        $orderStmt = $this->pdo->prepare($sql);
        $orderStmt->execute($params);
        $order = $orderStmt->fetch();
        if ($order) {
            $token = public_token(32);
            $this->pdo->prepare(
                'INSERT INTO ticket_order_access_links (order_id, token_hash, purpose, expires_at, created_at)
                 VALUES (?, ?, "recovery", DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())'
            )->execute([(int) $order['id'], hash('sha256', $token)]);
            $link = app_base_url() . '/mis-entradas/?token=' . rawurlencode($token);
            $this->mailer->queueOrderRecoveryEmail($this->pdo, (int) $order['id'], (string) $order['email'], (string) $order['name'], $link);
        }

        return ['message' => 'Si encontramos una compra asociada a esos datos, recibirás un enlace para acceder a tus entradas.'];
    }

    public function resendOrderEmail(string $token): array
    {
        $order = $this->getOrderRecordByToken($token);
        if (!$order || $this->effectiveOrderStatus($order) !== 'paid') {
            throw new RuntimeException('No se pueden reenviar entradas de este pedido.', 409);
        }

        $recent = $this->pdo->prepare(
            'SELECT created_at FROM email_deliveries WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $recent->execute([(int) $order['id']]);
        $lastAttempt = $recent->fetchColumn();
        if ($lastAttempt && strtotime((string) $lastAttempt) > time() - 300) {
            throw new RuntimeException('Ya hemos preparado un envio recientemente. Espera unos minutos antes de solicitar otro.', 409);
        }

        $link = app_base_url() . '/entradas/pedido/?token=' . rawurlencode((string) $order['public_token']);
        $this->mailer->queueOrderEmail(
            $this->pdo,
            (int) $order['id'],
            (string) $order['email'],
            'Tus entradas Perigallo',
            "Hola {$order['name']},\n\nPuedes consultar y descargar tus entradas aquí:\n{$link}\n\nContacto Perigallo: +34 691 499 985\n"
        );

        return ['message' => 'Hemos preparado un nuevo envio a ' . (string) $order['email'] . '.'];
    }

    public function resendOrderWhatsApp(string $token): array
    {
        $order = $this->getOrderRecordByToken($token);
        if (!$order || $this->effectiveOrderStatus($order) !== 'paid') {
            throw new RuntimeException('No se pueden reenviar entradas de este pedido.', 409);
        }
        $recent = $this->pdo->prepare(
            'SELECT status, created_at FROM ticket_delivery_logs WHERE order_id = ? AND channel = "whatsapp" ORDER BY id DESC LIMIT 1'
        );
        $recent->execute([(int) $order['id']]);
        $lastAttempt = $recent->fetch() ?: [];
        if (($lastAttempt['status'] ?? '') !== 'not_configured' && !empty($lastAttempt['created_at']) && strtotime((string) $lastAttempt['created_at']) > time() - 300) {
            throw new RuntimeException('Ya existe un intento reciente de WhatsApp. Espera unos minutos antes de volver a solicitarlo.', 409);
        }
        $eventStmt = $this->pdo->prepare('SELECT e.* FROM events e JOIN ticket_order_items toi ON toi.event_id = e.id WHERE toi.order_id = ? LIMIT 1');
        $eventStmt->execute([(int) $order['id']]);
        $event = $eventStmt->fetch();
        $quantityStmt = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM ticket_order_items WHERE order_id = ?');
        $quantityStmt->execute([(int) $order['id']]);
        if (!$event) {
            throw new RuntimeException('No se ha encontrado la experiencia de este pedido.', 409);
        }
        $status = (new WhatsAppDeliveryService())->sendOrder($this->pdo, $order, $event, (int) $quantityStmt->fetchColumn());
        $messages = [
            'sent' => 'Hemos enviado el enlace de tus entradas por WhatsApp.',
            'not_configured' => 'El envío por WhatsApp todavía no está configurado. Puedes descargar las entradas desde esta página.',
            'failed' => 'No se pudo enviar WhatsApp. Tus entradas siguen disponibles en esta página.',
        ];
        return ['status' => $status, 'message' => $messages[$status] ?? 'Estamos preparando el envío por WhatsApp.'];
    }

    /** Creates an admin-only sandbox order. It never reserves production capacity. */
    public function createTestOrder(int $eventId, array $data): array
    {
        $this->redsys->assertSandboxConfigured();
        require_fields($data, ['first_name', 'last_name', 'email', 'phone', 'items']);
        if (empty($data['privacy_accepted']) || empty($data['terms_accepted']) || !is_array($data['items'])) {
            throw new RuntimeException('Completa los datos y acepta las condiciones para continuar.');
        }
        $event = $this->requireAdminEvent($eventId);
        $testSession = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($data['test_session_id'] ?? '')) ?: public_token(18), 0, 96);
        $existing = $this->pdo->prepare('SELECT public_token, redsys_order, total_cents, status FROM ticket_orders WHERE test_session_id = ? AND is_test = 1 ORDER BY id DESC LIMIT 1');
        $existing->execute([$testSession]);
        $existingOrder = $existing->fetch();
        if ($existingOrder) {
            if ((string) $existingOrder['status'] === 'paid') {
                return [
                    'order' => $this->getOrderByToken((string) $existingOrder['public_token']),
                    'payment' => ['free' => true, 'url' => app_base_url() . '/entradas/pedido/?token=' . rawurlencode((string) $existingOrder['public_token'])],
                ];
            }
            return [
                'order' => $this->getOrderByToken((string) $existingOrder['public_token']),
                'payment' => ['sandbox' => true] + $this->redsysForm((string) $existingOrder['redsys_order'], (int) $existingOrder['total_cents'], $event, (string) $existingOrder['public_token']),
            ];
        }

        $this->pdo->beginTransaction();
        try {
            $publicToken = public_token();
            $redsysOrder = $this->nextRedsysOrder();
            $firstName = clean_string((string) $data['first_name'], 120);
            $lastName = clean_string((string) $data['last_name'], 160);
            $email = mb_strtolower(clean_string((string) $data['email'], 190));
            $phone = clean_string((string) $data['phone'], 60);
            $name = trim($firstName . ' ' . $lastName);
            $expires = (new DateTimeImmutable('now'))->add(new DateInterval('PT30M'))->format('Y-m-d H:i:s');
            $orderStmt = $this->pdo->prepare(
                'INSERT INTO ticket_orders
                 (public_token, redsys_order, first_name, last_name, name, email, phone, subtotal_cents, total_cents, currency, status, reservation_expires_at, ip_address, user_agent, is_test, environment, order_status, payment_status, delivery_status, test_session_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, "payment_processing", ?, ?, ?, 1, "sandbox", "pending_payment", "pending", "pending", ?, NOW(), NOW())'
            );
            $orderStmt->execute([$publicToken, $redsysOrder, $firstName, $lastName, $name, $email, $phone, env_value('REDSYS_CURRENCY', '978'), $expires, client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $testSession]);
            $orderId = (int) $this->pdo->lastInsertId();
            $reference = 'TEST-PG' . str_pad((string) $eventId, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE ticket_orders SET test_reference = ? WHERE id = ?')->execute([$reference, $orderId]);

            $total = 0;
            $quantityTotal = 0;
            foreach ($data['items'] as $item) {
                $typeId = (int) ($item['ticket_type_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($typeId <= 0 || $quantity <= 0) {
                    continue;
                }
                $type = $this->lockTestTicketType($typeId, $eventId, (string) ($data['promo_code'] ?? ''));
                if (!$type) {
                    throw new RuntimeException('Tipo de entrada no disponible para la prueba.');
                }
                if ($quantity < (int) $type['min_quantity'] || $quantity > (int) $type['max_per_order']) {
                    throw new RuntimeException('Cantidad no permitida para ' . $type['name'] . '.');
                }
                $unitPrice = (int) $type['price_cents'] + (int) round((int) $type['price_cents'] * (float) ($type['tax_rate'] ?? 0) / 100) + (int) ($type['fee_cents'] ?? 0);
                $lineTotal = $quantity * $unitPrice;
                $total += $lineTotal;
                $quantityTotal += $quantity;
                $this->pdo->prepare(
                    'INSERT INTO ticket_order_items (order_id, event_id, ticket_type_id, ticket_type_name, quantity, unit_price_cents, total_cents, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
                )->execute([$orderId, $eventId, $typeId, $type['name'], $quantity, $unitPrice, $lineTotal]);
            }
            if ($quantityTotal === 0) {
                throw new RuntimeException('Selecciona al menos una entrada para la prueba.');
            }
            $this->pdo->prepare('UPDATE ticket_orders SET subtotal_cents = ?, total_cents = ?, updated_at = NOW() WHERE id = ?')->execute([$total, $total, $orderId]);
            $this->pdo->prepare(
                'INSERT INTO payment_attempts (order_id, redsys_order, environment, amount_cents, currency, signature_version, status, created_at, updated_at)
                 VALUES (?, ?, "test", ?, ?, ?, "created", NOW(), NOW())'
            )->execute([$orderId, $redsysOrder, $total, env_value('REDSYS_CURRENCY', '978'), env_value('REDSYS_SIGNATURE_VERSION', 'HMAC_SHA256_V1')]);
            $this->pdo->commit();

            return [
                'order' => $this->getOrderByToken($publicToken),
                'payment' => ['sandbox' => true] + $this->redsysForm($redsysOrder, $total, $event, $publicToken),
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function processRedsysNotification(array $post): array
    {
        $merchantParameters = (string) ($post['Ds_MerchantParameters'] ?? $post['Ds_MerchantParameters'.PHP_EOL] ?? '');
        $signature = (string) ($post['Ds_Signature'] ?? '');
        $signatureVersion = (string) ($post['Ds_SignatureVersion'] ?? '');
        if ($merchantParameters === '' || $signature === '') {
            throw new RuntimeException('Notificacion Redsys incompleta.');
        }
        if ($signatureVersion !== (string) env_value('REDSYS_SIGNATURE_VERSION', 'HMAC_SHA256_V1')) {
            throw new RuntimeException('Version de firma Redsys no coincide.');
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
            $isTestOrder = !empty($order['is_test']);
            if ($isTestOrder && env_value('PAYMENT_ENVIRONMENT', 'sandbox') !== 'sandbox') {
                throw new RuntimeException('La notificación de pruebas no está permitida en este entorno.');
            }
            if ((string) ($attempt['environment'] ?? '') !== (string) env_value('REDSYS_ENV', 'test')) {
                throw new RuntimeException('El entorno Redsys de la notificación no coincide.');
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
            if ($terminal !== $this->redsys->terminal()) {
                throw new RuntimeException('Terminal Redsys no coincide.');
            }
            if ($transactionType !== (string) env_value('REDSYS_TRANSACTION_TYPE', '0')) {
                throw new RuntimeException('Tipo de operacion Redsys no coincide.');
            }

            $accepted = ctype_digit($responseCode) && (int) $responseCode >= 0 && (int) $responseCode <= 99;
            $attemptStatus = $accepted ? 'accepted' : 'denied';
            $orderStatus = $accepted ? 'paid' : 'denied';
            $commercialStatus = $accepted ? 'confirmed' : 'pending_payment';
            $paymentStatus = $accepted ? 'paid' : 'failed';
            $deliveryStatus = $accepted ? 'generated' : 'pending';

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
                $this->pdo->prepare('UPDATE ticket_orders SET status = ?, order_status = ?, payment_status = ?, delivery_status = ?, paid_at = IF(? = "paid", NOW(), paid_at), updated_at = NOW() WHERE id = ?')
                    ->execute([$orderStatus, $commercialStatus, $paymentStatus, $deliveryStatus, $orderStatus, $order['id']]);
                if ($accepted) {
                    $this->generateTicketsOnce((int) $order['id']);
                }
            }

            $this->pdo->commit();
            if ($accepted && $order['status'] !== 'paid') {
                if ($isTestOrder) {
                    $this->sendTestConfirmation((int) $order['id']);
                } else {
                    $this->sendConfirmation((int) $order['id']);
                }
            }
            error_log('Perigallo Redsys notification processed: order=' . $orderNumber . ' attempt=' . $attempt['id'] . ' response=' . $responseCode . ' accepted=' . ($accepted ? '1' : '0'));
            return ['ok' => true, 'accepted' => $accepted, 'order' => $orderNumber];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function adminSummary(): array
    {
        $orders = $this->pdo->query('SELECT status, COUNT(*) AS total, COALESCE(SUM(total_cents),0) AS amount FROM ticket_orders WHERE is_test = 0 GROUP BY status')->fetchAll();
        $events = $this->adminListEvents();
        return ['orders' => $orders, 'events' => $events];
    }

    public function adminOrders(): array
    {
        return $this->pdo->query('SELECT id, public_token, redsys_order, test_reference, is_test, environment, order_status, payment_status, delivery_status, name, email, phone, total_cents, status, reservation_expires_at, paid_at, created_at FROM ticket_orders ORDER BY id DESC LIMIT 200')->fetchAll();
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
        // El acceso solo mediante enlace nunca debe aparecer en los listados.
        // Se conserva el valor para que al desactivarlo el editor vuelva a mostrar
        // el ajuste de listados que corresponda.
        if (!empty($merged['link_only'])) {
            $merged['unlisted'] = true;
        }
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
            if (strtotime((string) $merged['publication_at']) <= time()) {
                throw new RuntimeException('La fecha programada debe ser posterior al momento actual.');
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
              starts_at=?, ends_at=?, doors_open_at=?, timezone=?, schedule_note=?, sale_starts_at=?, sale_ends_at=?, capacity=?, allow_reentry=?, maximum_reentries=?, reentry_until=?, require_manual_confirmation_for_reentry=?,
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
            array_key_exists('allow_reentry', $merged) && empty($merged['allow_reentry']) ? 0 : 1,
            isset($merged['maximum_reentries']) && (int) $merged['maximum_reentries'] > 0 ? (int) $merged['maximum_reentries'] : null,
            $this->nullableDate((string) ($merged['reentry_until'] ?? '')),
            array_key_exists('require_manual_confirmation_for_reentry', $merged) && empty($merged['require_manual_confirmation_for_reentry']) ? 0 : 1,
            trim((string) ($merged['included_text'] ?? '')),
            trim((string) ($merged['access_conditions'] ?? '')),
            trim((string) ($merged['minor_policy'] ?? '')),
            trim((string) ($merged['refund_policy'] ?? '')),
            $this->jsonArray($merged['faq'] ?? $merged['faq_json'] ?? []),
            trim((string) ($merged['contact_info'] ?? '')),
            trim((string) ($merged['recommendations'] ?? '')),
            trim((string) ($merged['dress_code'] ?? '')),
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

    /**
     * Guarda exclusivamente los textos públicos. No depende de fechas, SEO,
     * recursos visuales ni otros campos obligatorios del evento completo.
     */
    public function adminUpdatePublicInformation(int $eventId, array $data): array
    {
        $existing = $this->requireAdminEvent($eventId);
        $fields = ['included_text', 'access_conditions', 'minor_policy', 'refund_policy', 'contact_info', 'recommendations', 'dress_code', 'accessibility_info'];
        $values = [];
        foreach ($fields as $field) {
            $value = array_key_exists($field, $data) ? $data[$field] : ($existing[$field] ?? '');
            if ($value !== null && !is_string($value) && !is_numeric($value)) {
                throw new InvalidArgumentException('El campo ' . $field . ' debe contener texto.');
            }
            // trim elimina solo espacios exteriores: se conservan saltos de línea y párrafos internos.
            $values[$field] = trim((string) ($value ?? ''));
        }
        $faq = array_key_exists('faq', $data) ? $data['faq'] : ($existing['faq_json'] ?? null);
        if ($faq !== null && !is_array($faq) && !is_string($faq)) {
            throw new InvalidArgumentException('Las preguntas frecuentes no tienen un formato válido.');
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE events SET
                  included_text=?, access_conditions=?, minor_policy=?, refund_policy=?, faq_json=?, contact_info=?, recommendations=?, dress_code=?, accessibility_info=?, updated_at=NOW()
                  WHERE id=?'
            );
            $stmt->execute([
                $values['included_text'],
                $values['access_conditions'],
                $values['minor_policy'],
                $values['refund_policy'],
                $this->jsonArray($faq),
                $values['contact_info'],
                $values['recommendations'],
                $values['dress_code'],
                $values['accessibility_info'],
                $eventId,
            ]);
        } catch (\Throwable $error) {
            error_log('Public event information save failed for event ' . $eventId . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString());
            throw $error;
        }
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

    public function adminUploadImage(array $file, string $kind = 'image'): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = match ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite de subida del servidor. Configura upload_max_filesize y post_max_size en al menos 64M.',
                UPLOAD_ERR_PARTIAL => 'La subida del archivo no se completó. Inténtalo de nuevo.',
                default => 'No se pudo recibir el archivo.',
            };
            throw new InvalidArgumentException($message);
        }
        $allowedKinds = ['image', 'card', 'social', 'gallery', 'logo', 'video'];
        if (!in_array($kind, $allowedKinds, true)) {
            throw new InvalidArgumentException('Tipo de recurso no permitido.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('El archivo recibido no es válido.');
        }
        $isVideo = $kind === 'video';
        $maxSize = $isVideo ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > $maxSize) {
            throw new InvalidArgumentException($isVideo ? 'El vídeo debe pesar menos de 50 MB.' : 'La imagen debe pesar menos de 5 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extensions = $isVideo
            ? ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov']
            : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'];
        if (!isset($extensions[$mime])) {
            throw new InvalidArgumentException($isVideo ? 'Formato no permitido. Usa MP4, WebM o MOV.' : 'Formato no permitido. Usa JPG, PNG, WebP o AVIF.');
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedFileExtensions = $isVideo ? ['mp4', 'webm', 'mov'] : ['jpg', 'jpeg', 'png', 'webp', 'avif'];
        if (!in_array($extension, $allowedFileExtensions, true)) {
            throw new InvalidArgumentException($isVideo ? 'La extensión del vídeo no es válida.' : 'La extensión de la imagen no es válida.');
        }
        $directory = dirname(__DIR__, 2) . '/assets/uploads/events' . ($isVideo ? '/videos' : '');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el directorio de subidas.');
        }
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        $target = $directory . '/' . $name;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }
        return [
            'url' => '/assets/uploads/events' . ($isVideo ? '/videos' : '') . '/' . $name,
            'filename' => $name,
            'mime_type' => $mime,
            'media_type' => $isVideo ? 'video' : 'image',
        ];
    }

    public function previewTicketAccess(array $data): array
    {
        require_fields($data, ['event_id']);
        $eventId = (int) $data['event_id'];
        $scannedValue = clean_string((string) ($data['token'] ?? $data['code'] ?? ''), 512);
        if ($eventId < 1 || $scannedValue === '') {
            throw new InvalidArgumentException('Selecciona un evento e introduce o escanea una entrada.');
        }
        $event = $this->requireAdminEvent($eventId);
        $ticket = $this->findTicketForAccess($scannedValue);
        $mode = in_array((string) ($data['mode'] ?? 'automatic'), ['entry', 'exit', 'automatic'], true) ? (string) $data['mode'] : 'automatic';
        $result = $this->accessPreview($ticket, $event, $eventId, $mode);
        return [
            'result' => $result['result'],
            'action' => $result['action'],
            'message' => $result['message'],
            'mode' => $mode,
            'ticket' => $ticket ? $this->ticketAccessPayload($ticket) : null,
        ];
    }

    // Compatibilidad con instalaciones que llamaban al endpoint histórico de escaneo.
    // La operación ahora es una consulta estrictamente de solo lectura.
    public function scanTicket(array $data): array
    {
        return $this->previewTicketAccess($data);
    }

    public function registerAccessMovement(array $data): array
    {
        require_fields($data, ['event_id', 'action']);
        $eventId = (int) $data['event_id'];
        $scannedValue = clean_string((string) ($data['token'] ?? $data['code'] ?? ''), 512);
        $action = (string) $data['action'];
        if ($eventId < 1 || $scannedValue === '' || !in_array($action, ['entry', 'exit', 'reentry'], true)) {
            throw new InvalidArgumentException('Faltan datos para registrar el movimiento.');
        }
        $method = in_array((string) ($data['method'] ?? 'qr'), ['qr', 'manual'], true) ? (string) $data['method'] : 'qr';
        $device = clean_string((string) ($data['device_reference'] ?? ''), 190);
        $notes = clean_string((string) ($data['notes'] ?? ''), 500);
        $this->pdo->beginTransaction();
        try {
            $event = $this->requireAdminEvent($eventId);
            $ticket = $this->findTicketForAccess($scannedValue, true);
            if (!$ticket) {
                throw new RuntimeException('No encontramos una entrada válida.', 409);
            }
            if ((int) $ticket['event_id'] !== $eventId) {
                throw new RuntimeException('Esta entrada corresponde a otra experiencia.', 409);
            }
            if ((string) $ticket['status'] !== 'issued') {
                throw new RuntimeException($this->administrativeAccessMessage((string) $ticket['status']), 409);
            }
            $previous = (string) ($ticket['access_status'] ?? 'not_entered');
            $expected = $previous === 'not_entered' ? 'entry' : ($previous === 'inside' ? 'exit' : 'reentry');
            if ($action !== $expected) {
                throw new RuntimeException($this->unexpectedAccessActionMessage($previous), 409);
            }
            if ($action === 'reentry') {
                $this->assertReentryAllowed($event, $ticket);
            }

            $at = now_mysql();
            $next = $action === 'exit' ? 'outside' : 'inside';
            $entryCount = (int) ($ticket['entry_count'] ?? 0) + ($action === 'exit' ? 0 : 1);
            $exitCount = (int) ($ticket['exit_count'] ?? 0) + ($action === 'exit' ? 1 : 0);
            $firstEntryAt = $action === 'entry' ? ((string) ($ticket['first_entry_at'] ?? '') ?: $at) : ($ticket['first_entry_at'] ?? null);
            $lastEntryAt = $action === 'exit' ? ($ticket['last_entry_at'] ?? null) : $at;
            $lastExitAt = $action === 'exit' ? $at : ($ticket['last_exit_at'] ?? null);
            $update = $this->pdo->prepare(
                'UPDATE tickets SET access_status=?, first_entry_at=?, last_entry_at=?, last_exit_at=?, entry_count=?, exit_count=?, last_access_action=?, last_access_by=?, used_at=?, updated_at=NOW()
                 WHERE id=? AND event_id=? AND status="issued" AND access_status=?'
            );
            $update->execute([$next, $firstEntryAt, $lastEntryAt, $lastExitAt, $entryCount, $exitCount, $action, AdminAuth::operatorName(), $lastEntryAt, $ticket['id'], $eventId, $previous]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('El estado de esta entrada acaba de cambiar en otro dispositivo. Vuelve a escanearla.', 409);
            }
            $this->insertAccessMovement((int) $ticket['id'], $eventId, $action, $previous, $next, $method, $device, $notes);
            $this->pdo->commit();
            $ticket['access_status'] = $next;
            $ticket['first_entry_at'] = $firstEntryAt;
            $ticket['last_entry_at'] = $lastEntryAt;
            $ticket['last_exit_at'] = $lastExitAt;
            $ticket['entry_count'] = $entryCount;
            $ticket['exit_count'] = $exitCount;
            return ['result' => 'success', 'action' => $action, 'message' => $this->accessMovementMessage($action), 'ticket' => $this->ticketAccessPayload($ticket)];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function adminEventAttendees(int $eventId): array
    {
        $this->requireAdminEvent($eventId);
        $rows = $this->pdo->prepare(
            'SELECT t.id, t.public_code, t.status, t.access_status, t.first_entry_at, t.last_entry_at, t.last_exit_at, t.entry_count, t.exit_count, t.last_access_action, t.last_access_by, toi.ticket_type_name,
                    o.name, o.email, o.phone, COALESCE(o.test_reference, o.redsys_order) AS order_reference
             FROM tickets t
             JOIN ticket_order_items toi ON toi.id = t.order_item_id
             JOIN ticket_orders o ON o.id = toi.order_id
             WHERE t.event_id = ?
             ORDER BY FIELD(t.access_status, "inside", "outside", "not_entered"), t.last_entry_at DESC, o.created_at ASC, t.id ASC'
        );
        $rows->execute([$eventId]);
        $tickets = $rows->fetchAll();
        $metrics = ['issued' => 0, 'cancelled' => 0, 'refunded' => 0, 'blocked' => 0, 'not_entered' => 0, 'inside' => 0, 'outside' => 0, 'entries' => 0, 'exits' => 0, 'reentries' => 0];
        foreach ($tickets as $ticket) {
            $status = (string) $ticket['status'];
            $metrics[$status] = ($metrics[$status] ?? 0) + 1;
            if ($status === 'issued') {
                $presence = (string) ($ticket['access_status'] ?? 'not_entered');
                $metrics[$presence] = ($metrics[$presence] ?? 0) + 1;
                $metrics['entries'] += (int) ($ticket['entry_count'] ?? 0);
                $metrics['exits'] += (int) ($ticket['exit_count'] ?? 0);
                $metrics['reentries'] += max(0, (int) ($ticket['entry_count'] ?? 0) - 1);
            }
        }
        $metrics['total'] = count($tickets);
        $metrics['pending'] = $metrics['not_entered'];
        $metrics['access_percent'] = $metrics['issued'] > 0 ? (int) round($metrics['inside'] / $metrics['issued'] * 100) : 0;
        $history = $this->pdo->prepare(
            'SELECT m.action, m.previous_access_status, m.new_access_status, m.method, m.performed_by, m.device_reference, m.notes, m.created_at, t.public_code, o.name
             FROM ticket_access_movements m
             JOIN tickets t ON t.id = m.ticket_id
             JOIN ticket_order_items toi ON toi.id = t.order_item_id
             JOIN ticket_orders o ON o.id = toi.order_id
             WHERE m.event_id = ? ORDER BY m.id DESC LIMIT 100'
        );
        $history->execute([$eventId]);
        return ['metrics' => $metrics, 'attendees' => $tickets, 'history' => $history->fetchAll()];
    }

    public function reverseTicketCheckIn(int $eventId, string $code, string $reason = ''): array
    {
        $this->requireAdminEvent($eventId);
        $code = clean_string($code, 120);
        if ($code === '') {
            throw new InvalidArgumentException('Falta el código de la entrada.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM tickets WHERE event_id = ? AND public_code = ? FOR UPDATE');
            $stmt->execute([$eventId, $code]);
            $ticket = $stmt->fetch();
            if (!$ticket) {
                throw new InvalidArgumentException('Entrada no encontrada para esta experiencia.');
            }
            $movement = $this->pdo->prepare(
                'SELECT m.* FROM ticket_access_movements m
                 WHERE m.ticket_id = ? AND m.action IN ("entry", "exit", "reentry")
                   AND NOT EXISTS (SELECT 1 FROM ticket_access_movements r WHERE r.reversal_of_id = m.id)
                 ORDER BY m.id DESC LIMIT 1 FOR UPDATE'
            );
            $movement->execute([$ticket['id']]);
            $last = $movement->fetch();
            if (!$last) {
                throw new RuntimeException('No hay ningún movimiento de acceso que revertir.', 409);
            }
            $this->insertAccessMovement((int) $ticket['id'], $eventId, 'reversal', (string) $last['new_access_status'], (string) $last['previous_access_status'], 'manual', clean_string($reason, 190), clean_string($reason, 500), (int) $last['id']);
            $summary = $this->rebuildAccessSummary((int) $ticket['id']);
            $this->pdo->prepare('UPDATE tickets SET access_status=?, first_entry_at=?, last_entry_at=?, last_exit_at=?, entry_count=?, exit_count=?, last_access_action="reversal", last_access_by=?, used_at=?, updated_at=NOW() WHERE id=?')
                ->execute([$summary['access_status'], $summary['first_entry_at'], $summary['last_entry_at'], $summary['last_exit_at'], $summary['entry_count'], $summary['exit_count'], AdminAuth::operatorName(), $summary['last_entry_at'], $ticket['id']]);
            $this->pdo->commit();
            return ['public_code' => $code, 'access_status' => $summary['access_status']];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function findTicketForAccess(string $scannedValue, bool $forUpdate = false): ?array
    {
        $qrToken = $this->extractQrToken($scannedValue);
        $stmt = $this->pdo->prepare(
            'SELECT t.*, o.name AS attendee_name, COALESCE(o.test_reference, o.redsys_order) AS order_reference, toi.ticket_type_name
             FROM tickets t
             JOIN ticket_order_items toi ON toi.id = t.order_item_id
             JOIN ticket_orders o ON o.id = toi.order_id
             WHERE ' . ($qrToken !== null ? 't.qr_token_hash = ?' : 't.public_code = ?') . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$qrToken !== null ? hash('sha256', $qrToken) : $scannedValue]);
        return $stmt->fetch() ?: null;
    }

    private function accessPreview(?array $ticket, array $event, int $eventId, string $mode): array
    {
        if (!$ticket) return ['result' => 'inexistente', 'action' => null, 'message' => 'No encontramos una entrada válida.', 'audit_result' => 'inexistente'];
        if ((int) $ticket['event_id'] !== $eventId) return ['result' => 'otro_evento', 'action' => null, 'message' => 'Esta entrada corresponde a otra experiencia.', 'audit_result' => 'otro_evento'];
        if ((string) $ticket['status'] !== 'issued') return ['result' => (string) $ticket['status'], 'action' => null, 'message' => $this->administrativeAccessMessage((string) $ticket['status']), 'audit_result' => $this->auditResultForStatus((string) $ticket['status'])];
        $presence = (string) ($ticket['access_status'] ?? 'not_entered');
        if ($mode === 'entry' && $presence === 'inside') return ['result' => 'already_inside', 'action' => null, 'message' => 'El asistente ya está dentro del recinto.', 'audit_result' => 'dentro'];
        if ($mode === 'exit' && $presence === 'outside') return ['result' => 'already_outside', 'action' => null, 'message' => 'La salida ya está registrada.', 'audit_result' => 'fuera'];
        if ($mode === 'exit' && $presence === 'not_entered') return ['result' => 'not_entered', 'action' => null, 'message' => 'Esta entrada todavía no ha accedido.', 'audit_result' => 'sin_acceder'];
        $action = $presence === 'not_entered' ? 'entry' : ($presence === 'inside' ? 'exit' : 'reentry');
        if ($action === 'reentry' && empty($event['allow_reentry'])) return ['result' => 'reentry_not_allowed', 'action' => null, 'message' => 'La reentrada no está permitida para este evento.', 'audit_result' => 'reentrada_no_permitida'];
        return ['result' => 'ready', 'action' => $action, 'message' => $this->accessMovementPrompt($action), 'audit_result' => $presence === 'inside' ? 'dentro' : ($presence === 'outside' ? 'fuera' : 'sin_acceder')];
    }

    private function insertAccessMovement(int $ticketId, int $eventId, string $action, string $previous, string $next, string $method, string $device, string $notes, ?int $reversalOfId = null): void
    {
        $this->pdo->prepare('INSERT INTO ticket_access_movements (ticket_id, event_id, action, previous_access_status, new_access_status, method, performed_by, device_reference, notes, reversal_of_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$ticketId, $eventId, $action, $previous, $next, $method, AdminAuth::operatorName(), $device ?: null, $notes ?: null, $reversalOfId]);
    }

    private function assertReentryAllowed(array $event, array $ticket): void
    {
        if (empty($event['allow_reentry'])) throw new RuntimeException('La reentrada no está permitida para este evento.', 409);
        $limit = (int) ($event['maximum_reentries'] ?? 0);
        if ($limit > 0 && max(0, (int) ($ticket['entry_count'] ?? 0) - 1) >= $limit) throw new RuntimeException('Esta entrada ha alcanzado el máximo de reentradas permitido.', 409);
        $until = (string) ($event['reentry_until'] ?? '');
        if ($until !== '' && strtotime($until) < time()) throw new RuntimeException('La hora límite para reentrar ya ha finalizado.', 409);
    }

    private function rebuildAccessSummary(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT action, created_at FROM ticket_access_movements m WHERE m.ticket_id = ? AND m.action IN ("entry", "exit", "reentry") AND NOT EXISTS (SELECT 1 FROM ticket_access_movements r WHERE r.reversal_of_id = m.id) ORDER BY m.id ASC');
        $stmt->execute([$ticketId]);
        $movements = $stmt->fetchAll();
        $entries = array_values(array_filter($movements, fn (array $movement) => in_array($movement['action'], ['entry', 'reentry'], true)));
        $exits = array_values(array_filter($movements, fn (array $movement) => $movement['action'] === 'exit'));
        $last = $movements ? $movements[count($movements) - 1] : null;
        return ['access_status' => !$last ? 'not_entered' : ($last['action'] === 'exit' ? 'outside' : 'inside'), 'first_entry_at' => $entries[0]['created_at'] ?? null, 'last_entry_at' => $entries ? $entries[count($entries) - 1]['created_at'] : null, 'last_exit_at' => $exits ? $exits[count($exits) - 1]['created_at'] : null, 'entry_count' => count($entries), 'exit_count' => count($exits)];
    }

    private function ticketAccessPayload(array $ticket): array
    {
        return ['id' => (int) $ticket['id'], 'public_code' => $ticket['public_code'], 'status' => $ticket['status'], 'access_status' => $ticket['access_status'] ?? 'not_entered', 'first_entry_at' => $ticket['first_entry_at'] ?? null, 'last_entry_at' => $ticket['last_entry_at'] ?? null, 'last_exit_at' => $ticket['last_exit_at'] ?? null, 'entry_count' => (int) ($ticket['entry_count'] ?? 0), 'exit_count' => (int) ($ticket['exit_count'] ?? 0), 'last_access_by' => $ticket['last_access_by'] ?? null, 'attendee_name' => $ticket['attendee_name'], 'ticket_type_name' => $ticket['ticket_type_name'], 'order_reference' => $ticket['order_reference'] ?? null];
    }

    private function administrativeAccessMessage(string $status): string
    {
        return ['cancelled' => 'Esta entrada está cancelada.', 'refunded' => 'Esta entrada está reembolsada.', 'blocked' => 'Esta entrada está bloqueada.'][ $status ] ?? 'Esta entrada no puede acceder.';
    }

    private function auditResultForStatus(string $status): string
    {
        return ['cancelled' => 'cancelada', 'refunded' => 'reembolsada', 'blocked' => 'bloqueada'][ $status ] ?? 'inexistente';
    }

    private function unexpectedAccessActionMessage(string $presence): string
    {
        return ['not_entered' => 'Esta entrada todavía no ha accedido.', 'inside' => 'El asistente ya está dentro del recinto.', 'outside' => 'La salida ya está registrada.'][ $presence ] ?? 'El estado de acceso no es válido.';
    }

    private function accessMovementPrompt(string $action): string
    {
        return ['entry' => 'Primera entrada preparada para confirmar.', 'exit' => 'El asistente está dentro. Confirma la salida.', 'reentry' => 'El asistente está fuera. Confirma la reentrada.'][ $action ] ?? 'Movimiento pendiente.';
    }

    private function accessMovementMessage(string $action): string
    {
        return ['entry' => 'Acceso autorizado.', 'exit' => 'Salida registrada.', 'reentry' => 'Reentrada autorizada.'][ $action ] ?? 'Movimiento registrado.';
    }

    private function adminEvent(array $event): array
    {
        $metrics = $this->eventMetrics((int) $event['id']);
        $event['id'] = (int) $event['id'];
        $event['capacity'] = (int) $event['capacity'];
        $event['allow_reentry'] = !array_key_exists('allow_reentry', $event) || (bool) $event['allow_reentry'];
        $event['maximum_reentries'] = isset($event['maximum_reentries']) ? (int) $event['maximum_reentries'] : null;
        $event['require_manual_confirmation_for_reentry'] = !array_key_exists('require_manual_confirmation_for_reentry', $event) || (bool) $event['require_manual_confirmation_for_reentry'];
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
             FROM ticket_order_items toi JOIN ticket_orders tor ON tor.id=toi.order_id WHERE toi.event_id=? AND tor.is_test = 0'
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
             FROM ticket_order_items toi JOIN ticket_orders tor ON tor.id=toi.order_id WHERE toi.ticket_type_id=? AND tor.is_test = 0'
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
            'logo_url' => $event['logo_url'] ?? null,
            'location' => $event['location'],
            'address' => $event['address'],
            'postal_code' => $event['postal_code'] ?? null,
            'locality' => $event['locality'] ?? null,
            'province' => $event['province'] ?? null,
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

    private function lockTestTicketType(int $typeId, int $eventId, string $promoCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE id = ? AND event_id = ? AND status <> "archived" FOR UPDATE');
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
             AND tor.is_test = 0
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

    private function redsysForm(string $redsysOrder, int $amountCents, array $event, string $publicToken): array
    {
        $base = app_base_url();
        $params = [
            'DS_MERCHANT_AMOUNT' => (string) $amountCents,
            'DS_MERCHANT_ORDER' => $redsysOrder,
            'DS_MERCHANT_MERCHANTCODE' => env_value('REDSYS_MERCHANT_CODE', ''),
            'DS_MERCHANT_CURRENCY' => env_value('REDSYS_CURRENCY', '978'),
            'DS_MERCHANT_TRANSACTIONTYPE' => env_value('REDSYS_TRANSACTION_TYPE', '0'),
            'DS_MERCHANT_TERMINAL' => $this->redsys->terminal(),
            'DS_MERCHANT_MERCHANTURL' => $base . '/api/redsys/notification',
            'DS_MERCHANT_URLOK' => $base . '/entradas/pago/correcto/?token=' . rawurlencode($publicToken),
            'DS_MERCHANT_URLKO' => $base . '/entradas/pago/error/?token=' . rawurlencode($publicToken),
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
        $orderStmt = $this->pdo->prepare('SELECT is_test, test_reference FROM ticket_orders WHERE id = ?');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch() ?: [];
        $items = $this->pdo->prepare('SELECT * FROM ticket_order_items WHERE order_id = ? ORDER BY id ASC');
        $items->execute([$orderId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO tickets (order_item_id, event_id, ticket_type_id, public_code, qr_token_hash, qr_token_ciphertext, status, issued_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, "issued", NOW(), NOW(), NOW())'
        );
        foreach ($items->fetchAll() as $item) {
            for ($i = 0; $i < (int) $item['quantity']; $i++) {
                $token = public_token(32);
                $code = !empty($order['is_test'])
                    ? 'PG-TEST-' . strtoupper(substr(bin2hex(random_bytes(7)), 0, 10))
                    : 'PG-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
                $ciphertext = null;
                try {
                    $ciphertext = $this->encryptQrToken($token);
                } catch (RuntimeException $error) {
                    // La confirmación del banco nunca se bloquea por una clave de QR aún no configurada.
                    // El QR se emitirá de forma segura al abrir el pedido cuando esa clave exista.
                }
                $insert->execute([
                    $item['id'],
                    $item['event_id'],
                    $item['ticket_type_id'],
                    $code,
                    hash('sha256', $token),
                    $ciphertext,
                ]);
            }
        }
    }

    private function getOrderRecordByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE public_token = ? LIMIT 1');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    private function ticketQrUrl(array $ticket): string
    {
        $token = $this->decryptQrToken((string) ($ticket['qr_token_ciphertext'] ?? ''));
        if ($token === '') {
            $token = public_token(32);
            $this->pdo->prepare('UPDATE tickets SET qr_token_hash = ?, qr_token_ciphertext = ?, updated_at = NOW() WHERE id = ?')
                ->execute([hash('sha256', $token), $this->encryptQrToken($token), (int) $ticket['id']]);
        }
        return app_base_url() . '/check-in/?ticket=' . rawurlencode($token);
    }

    private function qrEncryptionKey(): string
    {
        $configured = env_value('TICKET_QR_ENCRYPTION_KEY');
        if (!$configured || strlen($configured) < 32) {
            throw new RuntimeException('Falta configurar la clave privada de QR.');
        }
        return hash('sha256', $configured, true);
    }

    private function encryptQrToken(string $token): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('El servidor no dispone del cifrado necesario para los codigos QR.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $this->qrEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('No se pudo proteger el codigo QR.');
        }
        return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
    }

    private function decryptQrToken(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('El servidor no dispone del cifrado necesario para los codigos QR.');
        }
        $encoded = strtr($value, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) < 29) {
            return '';
        }
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $token = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->qrEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($token) ? $token : '';
    }

    private function extractQrToken(string $value): ?string
    {
        if (preg_match('#[?&]ticket=([A-Za-z0-9_-]{32,})#', $value, $matches)) {
            return $matches[1];
        }
        return preg_match('/^[A-Za-z0-9_-]{32,}$/', $value) ? $value : null;
    }

    private function sendConfirmation(int $orderId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            return;
        }
        $eventStmt = $this->pdo->prepare('SELECT e.* FROM events e JOIN ticket_order_items toi ON toi.event_id = e.id WHERE toi.order_id = ? LIMIT 1');
        $eventStmt->execute([$orderId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            return;
        }
        $quantityStmt = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM ticket_order_items WHERE order_id = ?');
        $quantityStmt->execute([$orderId]);
        $result = (new TicketDeliveryService($this->mailer))->sendOrder($this->pdo, $order, $event, (int) $quantityStmt->fetchColumn());
        $status = $result['email'] === 'sent' && $result['whatsapp'] === 'sent' ? 'sent' : ($result['email'] === 'sent' ? 'partially_sent' : 'failed');
        $this->pdo->prepare('UPDATE ticket_orders SET delivery_status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $orderId]);
    }

    private function sendTestConfirmation(int $orderId): void
    {
        $orderStmt = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE id = ? AND is_test = 1');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order) {
            return;
        }
        $eventStmt = $this->pdo->prepare('SELECT e.* FROM events e JOIN ticket_order_items toi ON toi.event_id = e.id WHERE toi.order_id = ? LIMIT 1');
        $eventStmt->execute([$orderId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            return;
        }
        $quantityStmt = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM ticket_order_items WHERE order_id = ?');
        $quantityStmt->execute([$orderId]);
        $quantity = (int) $quantityStmt->fetchColumn();
        $delivery = new TicketDeliveryService($this->mailer);
        $result = $delivery->sendOrder($this->pdo, $order, $event, $quantity);
        $status = $result['email'] === 'sent' ? 'partially_sent' : 'failed';
        $this->pdo->prepare('UPDATE ticket_orders SET delivery_status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $orderId]);
    }
}
