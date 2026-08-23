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
    private ?DiscountCodes $discountCodes = null;
    private ?bool $cashOrderSchemaAvailable = null;
    private ?bool $manualReserveInventorySchemaAvailable = null;
    private ?bool $ticketItemTaxBreakdownAvailable = null;
    private ?bool $ticketAttendeeDietarySchemaAvailable = null;
    private const FOOD_ALLERGENS = [
        'gluten' => 'Cereales con gluten',
        'crustaceans' => 'Crustáceos',
        'eggs' => 'Huevos',
        'fish' => 'Pescado',
        'peanuts' => 'Cacahuetes',
        'soy' => 'Soja',
        'milk' => 'Leche',
        'nuts' => 'Frutos de cáscara',
        'celery' => 'Apio',
        'mustard' => 'Mostaza',
        'sesame' => 'Sésamo',
        'sulphites' => 'Sulfitos',
        'lupin' => 'Altramuces',
        'molluscs' => 'Moluscos',
    ];
    private const DRESS_CODE_VERSION = 'total-white-v1';

    public function __construct(
        private PDO $pdo,
        private Redsys $redsys,
        private Mailer $mailer
    ) {
    }

    /**
     * Carga el módulo de campañas únicamente cuando se necesita. Así el panel
     * básico no queda inutilizado durante un despliegue parcial del módulo.
     */
    private function discounts(): DiscountCodes
    {
        return $this->discountCodes ??= new DiscountCodes($this->pdo);
    }

    public function listEvents(): array
    {
        $stmt = $this->pdo->query(
            'SELECT e.*,
                    (SELECT MIN(tt.price_cents + ROUND(tt.price_cents * tt.tax_rate / 100) + tt.fee_cents)
                     FROM ticket_types tt
                     WHERE tt.event_id = e.id AND tt.active = 1 AND tt.visible = 1) AS price_from_cents
                    ,(SELECT MIN(tt.reference_price_cents)
                      FROM ticket_types tt
                      WHERE tt.event_id = e.id
                        AND tt.active = 1
                        AND tt.visible = 1
                        AND tt.show_reference_price = 1
                        AND tt.reference_price_cents > tt.price_cents + ROUND(tt.price_cents * tt.tax_rate / 100) + tt.fee_cents) AS reference_price_from_cents
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

    public function publicPaymentMethods(): array
    {
        return $this->redsys->availablePaymentMethods();
    }

    /** Quote a discount from server-side ticket prices. The browser never decides the amount. */
    public function validateDiscount(array $data): array
    {
        require_fields($data, ['event_slug', 'items']);
        if (!is_array($data['items'])) {
            throw new InvalidArgumentException('Selecciona al menos una entrada para comprobar el código.');
        }
        $event = $this->findEventForSale((string) $data['event_slug']);
        $items = $this->pricedItemsForDiscount((int) $event['id'], $data['items']);
        if (!$items) {
            throw new InvalidArgumentException('Selecciona al menos una entrada para comprobar el código.');
        }
        return $this->discounts()->validatePublic($data, $event, $items);
    }

    public function validateTestDiscount(int $eventId, array $data): array
    {
        $event = $this->requireAdminEvent($eventId);
        $items = $this->pricedItemsForDiscount($eventId, is_array($data['items'] ?? null) ? $data['items'] : [], true);
        if (!$items) {
            throw new InvalidArgumentException('Selecciona al menos una entrada para comprobar el código.');
        }
        return $this->discounts()->validatePublic($data, $event, $items);
    }

    public function createOrder(array $data): array
    {
        require_fields($data, ['event_slug', 'first_name', 'last_name', 'email', 'phone', 'items']);
        if (empty($data['privacy_accepted']) || empty($data['terms_accepted'])) {
            throw new RuntimeException('Debes aceptar privacidad y condiciones de compra.');
        }
        if (empty($data['age_requirement_accepted'])) {
            throw new RuntimeException('Confirma que eres mayor de 18 años.');
        }
        if (empty($data['dress_code_accepted'])) {
            throw new RuntimeException('Debes aceptar el código de vestimenta Total White para continuar.');
        }
        if (!is_array($data['items']) || count($data['items']) === 0) {
            throw new RuntimeException('Selecciona al menos una entrada.');
        }
        $billing = $this->normaliseBilling($data);
        $whatsApp = $this->normaliseWhatsAppPhone($data);
        // Si un despliegue deja el esquema incompleto, debemos poder indicar al
        // comprador la fase afectada sin mostrar detalles internos ni secretos.
        $checkoutStage = 'validar la reserva';

        $this->pdo->beginTransaction();
        try {
            $checkoutStage = 'comprobar el evento y la forma de pago';
            $event = $this->findEventForSale((string) $data['event_slug']);
            $paymentMethod = $this->redsys->paymentMethod((string) ($data['payment_method'] ?? 'card'));
            $reservationMinutes = max(5, (int) (env_value('TICKET_RESERVATION_MINUTES', '30') ?? '30'));
            $expires = (new DateTimeImmutable('now'))->add(new DateInterval('PT' . $reservationMinutes . 'M'))->format('Y-m-d H:i:s');
            $publicToken = public_token();
            $redsysOrder = $this->nextRedsysOrder();

            $orderStmt = $this->pdo->prepare(
                'INSERT INTO ticket_orders
                 (public_token, redsys_order, first_name, last_name, name, email, phone, whatsapp_phone_input, whatsapp_phone_e164, whatsapp_country_code, whatsapp_consent, whatsapp_consent_at, whatsapp_consent_source, whatsapp_consent_version, marketing_email_consent, marketing_whatsapp_consent, marketing_email_consent_version, marketing_whatsapp_consent_version, age_requirement_accepted, age_requirement_accepted_at, dress_code_accepted, dress_code_accepted_at, dress_code_version, billing_requested, billing_name, billing_tax_id, billing_address, billing_postal_code, billing_city, billing_province, billing_country, billing_email, subtotal_cents, total_cents, currency, status, reservation_expires_at, ip_address, user_agent, is_test, environment, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL), ?, ?, ?, ?, ?, ?, 1, NOW(), 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, "pending", ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $firstName = clean_string((string) $data['first_name'], 120);
            $lastName = clean_string((string) $data['last_name'], 160);
            $email = mb_strtolower(clean_string((string) $data['email'], 190));
            $phone = clean_string((string) $data['phone'], 60);
            $name = trim($firstName . ' ' . $lastName);
            $checkoutStage = 'guardar los datos de la reserva';
            $orderStmt->execute([
                $publicToken,
                $redsysOrder,
                $firstName,
                $lastName,
                $name,
                $email,
                $phone,
                $whatsApp['input'],
                $whatsApp['e164'],
                $whatsApp['country'],
                $whatsApp['consent'] ? 1 : 0,
                $whatsApp['consent'] ? 1 : 0,
                $whatsApp['consent'] ? 'checkout' : null,
                $whatsApp['consent'] ? 'v1' : null,
                !empty($data['marketing_email_consent']) ? 1 : 0,
                !empty($data['marketing_whatsapp_consent']) ? 1 : 0,
                !empty($data['marketing_email_consent']) ? 'marketing_email_v1' : null,
                !empty($data['marketing_whatsapp_consent']) ? 'marketing_whatsapp_v1' : null,
                self::DRESS_CODE_VERSION,
                $billing['requested'],
                $billing['name'],
                $billing['tax_id'],
                $billing['address'],
                $billing['postal_code'],
                $billing['city'],
                $billing['province'],
                $billing['country'],
                $billing['email'],
                env_value('REDSYS_CURRENCY', '978'),
                $expires,
                client_ip(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                !empty($event['is_test']) ? 1 : 0,
                !empty($event['is_test']) ? 'sandbox' : 'production',
            ]);
            $orderId = (int) $this->pdo->lastInsertId();

            $subtotal = 0;
            $selectedItems = 0;
            $quantityTotal = 0;
            $orderItems = [];
            $pricedItems = [];
            $checkoutStage = 'reservar las entradas';
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
                $available = $this->onlineAvailableForType($type);
                if ($quantity > $available) {
                    throw new RuntimeException('No quedan suficientes entradas para ' . $type['name'] . '.');
                }
                // El precio administrado es la base imponible. El importe cobrado
                // y enviado a Redsys siempre incluye el IVA y los gastos visibles.
                $unitBase = (int) $type['price_cents'];
                $taxRate = max(0, (float) ($type['tax_rate'] ?? 0));
                $taxCents = (int) round($unitBase * $taxRate / 100);
                $unitFee = (int) ($type['fee_cents'] ?? 0);
                $unitPrice = $unitBase + $taxCents + $unitFee;
                $lineTotal = $quantity * $unitPrice;
                $subtotal += $lineTotal;
                $selectedItems++;
                $quantityTotal += $quantity;
                $referenceUnitPrice = $this->visibleReferencePrice($type, $unitPrice);
                $pricedItems[] = ['ticket_type_id' => $typeId, 'quantity' => $quantity, 'unit_price_cents' => $unitPrice];
                $orderItems[] = [
                    'id' => $this->insertOrderItem(
                        $orderId,
                        (int) $event['id'],
                        $typeId,
                        (string) $type['name'],
                        $quantity,
                        $unitPrice,
                        $unitBase,
                        $taxCents,
                        $taxRate,
                        $unitFee,
                        $referenceUnitPrice,
                        $lineTotal,
                        $this->promotionalLabel($type)
                    ),
                    'quantity' => $quantity,
                ];
            }

            if ($selectedItems === 0) {
                throw new RuntimeException('El pedido no contiene entradas validas.');
            }

            $checkoutStage = 'guardar la información de los asistentes';
            $this->persistAttendees(
                $orderId,
                $orderItems,
                $this->normaliseAttendees($data['attendees'] ?? null, $quantityTotal, $name)
            );

            $checkoutStage = 'calcular el importe';
            $discount = $this->discounts()->quote(
                (string) ($data['discount_code'] ?? ''),
                (int) $event['id'],
                $pricedItems,
                $email,
                $phone,
                true
            );
            $total = (int) $discount['total_cents'];
            $this->pdo->prepare(
                'UPDATE ticket_orders
                 SET subtotal_cents = ?, discount_code_id = ?, discount_code = ?, discount_type = ?, discount_value_snapshot = ?, discount_amount_cents = ?, discount_snapshot = ?, discount_applied_at = ?, total_cents = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $subtotal,
                $discount['code_id'] ?? null,
                $discount['code'] ?? null,
                $discount['type'] ?? null,
                $discount['value_label'] ?? null,
                (int) $discount['discount_cents'],
                !empty($discount['snapshot']) ? json_encode($discount['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                !empty($discount['applied']) ? now_mysql() : null,
                $total,
                $orderId,
            ]);
            $this->discounts()->reserve($discount, $orderId, (int) $event['id'], $email, $phone, $expires);

            // Las invitaciones gratuitas emiten la entrada directamente: no deben pasar por Redsys.
            if ($total === 0) {
                $this->pdo->prepare('UPDATE ticket_orders SET status = "paid", paid_at = NOW(), updated_at = NOW() WHERE id = ?')
                    ->execute([$orderId]);
                $this->generateTicketsOnce($orderId);
                $this->discounts()->consumeForOrder($orderId);
                $this->pdo->commit();
                $checkoutStage = 'confirmar la reserva gratuita';
                $this->sendConfirmation($orderId);
                return [
                    'order' => $this->getOrderByToken($publicToken),
                    'payment' => [
                        'free' => true,
                        'url' => app_base_url() . '/entradas/pedido/?token=' . rawurlencode($publicToken),
                    ],
                ];
            }

            $checkoutStage = 'comprobar la conexión con Redsys';
            $this->redsys->assertConfigured();
            $checkoutStage = 'registrar el intento de pago';
            $this->pdo->prepare(
                'INSERT INTO payment_attempts
                 (order_id, redsys_order, environment, amount_cents, currency, signature_version, payment_method, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "created", NOW(), NOW())'
            )->execute([
                $orderId,
                $redsysOrder,
                env_value('REDSYS_ENV', 'test'),
                $total,
                env_value('REDSYS_CURRENCY', '978'),
                env_value('REDSYS_SIGNATURE_VERSION', 'HMAC_SHA256_V1'),
                $paymentMethod,
            ]);

            $this->pdo->prepare('UPDATE ticket_orders SET status = "payment_processing", updated_at = NOW() WHERE id = ?')
                ->execute([$orderId]);

            $this->pdo->commit();

            $checkoutStage = 'preparar la redirección segura a Redsys';
            return [
                'order' => $this->getOrderByToken($publicToken),
                'payment' => $this->redsysForm($redsysOrder, $total, $event, $publicToken, $paymentMethod),
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            error_log('Perigallo checkout preparation failed at ' . $checkoutStage . ': ' . $e->getMessage());
            throw new RuntimeException('No se pudo preparar el pedido al ' . $checkoutStage . '.', 422, $e);
        }
    }

    public function getOrderByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, (
                SELECT pa.payment_method
                FROM payment_attempts pa
                WHERE pa.order_id = o.id
                ORDER BY pa.id DESC
                LIMIT 1
             ) AS payment_method
             FROM ticket_orders o
             WHERE o.public_token = ?
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }

        $items = $this->pdo->prepare('SELECT * FROM ticket_order_items WHERE order_id = ? ORDER BY id ASC');
        $items->execute([(int) $order['id']]);
        $orderItems = $this->orderItemsWithReference($items->fetchAll());

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
            'payment_method' => $order['payment_method'] ?? null,
            'delivery_status' => $order['delivery_status'] ?? null,
            'name' => $order['name'],
            'email' => $order['email'],
            'phone' => $order['phone'],
            'total_cents' => (int) $order['total_cents'],
            'reference_total_cents' => $this->orderReferenceTotal($orderItems),
            'currency' => $order['currency'],
            'reservation_expires_at' => $order['reservation_expires_at'],
            'paid_at' => $order['paid_at'],
            'items' => $orderItems,
            'tickets' => $ticketRows,
            'deliveries' => $delivery->fetchAll(),
            'email_delivery' => $emailDelivery->fetch() ?: null,
            'invoice' => [
                'requested' => !empty($order['billing_requested']),
                'available' => $order['holded_status'] === 'synced'
                    && $order['holded_document_type'] === 'invoice'
                    && !empty($order['holded_document_id']),
                'number' => $order['holded_document_type'] === 'invoice' ? $order['holded_document_number'] : null,
                'delivery_status' => $order['holded_invoice_delivery_status'] ?? 'not_required',
            ],
        ];
    }

    /** @return array{content:string,filename:string}|null */
    public function invoicePdfByToken(string $token): ?array
    {
        $order = $this->getOrderRecordByToken($token);
        if (!$order
            || $this->effectiveOrderStatus($order) !== 'paid'
            || empty($order['billing_requested'])
            || ($order['holded_status'] ?? '') !== 'synced'
            || ($order['holded_document_type'] ?? '') !== 'invoice'
            || empty($order['holded_document_id'])) {
            return null;
        }

        $content = (new HoldedClient())->invoicePdf((string) $order['holded_document_id']);
        $this->pdo->prepare('UPDATE ticket_orders SET holded_pdf_available = 1, updated_at = NOW() WHERE id = ?')
            ->execute([(int) $order['id']]);
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($order['holded_document_number'] ?: $order['redsys_order'])) ?: 'factura';
        return ['content' => $content, 'filename' => 'factura-' . $number . '.pdf'];
    }

    /** @return array{content:string,filename:string,content_type:string,sha256:string}|null */
    public function ticketPdfByToken(string $token): ?array
    {
        $order = $this->getOrderRecordByToken($token);
        if (!$order || $this->effectiveOrderStatus($order) !== 'paid') {
            return null;
        }

        // El token público ya es el acceso que se utiliza en la página de pedido.
        // Reutilizarlo aquí evita crear otra URL menos clara para la descarga desde WhatsApp.
        return (new TicketDocumentService())->ensureOrderDocument($this->pdo, (int) $order['id']);
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

        (new TicketDeliveryQueue($this->pdo, $this->mailer))->requeue((int) $order['id'], 'email', 'buyer');
        return ['message' => 'Hemos dejado el reenvío por correo en cola.'];
    }

    public function resendOrderWhatsApp(string $token): array
    {
        $order = $this->getOrderRecordByToken($token);
        if (!$order || $this->effectiveOrderStatus($order) !== 'paid') {
            throw new RuntimeException('No se pueden reenviar entradas de este pedido.', 409);
        }
        (new TicketDeliveryQueue($this->pdo, $this->mailer))->requeue((int) $order['id'], 'whatsapp', 'buyer');
        return ['status' => 'queued', 'message' => 'Hemos dejado el reenvío por WhatsApp en cola.'];
    }

    public function adminRetryDelivery(int $orderId, string $channel, string $operator): array
    {
        $result = (new TicketDeliveryQueue($this->pdo, $this->mailer))->requeue($orderId, $channel, $operator);
        $this->auditAdminOperation($operator, 'ticket_delivery_requeued', $orderId, ['channel' => $channel, 'idempotency_key' => $result['idempotency_key']]);
        return $result;
    }

    /** Marks a historical email as received without creating a new delivery. */
    public function adminConfirmEmailDelivered(int $orderId, string $operator): array
    {
        $order = $this->lockedOrder($orderId);
        if ($this->effectiveOrderStatus($order) !== 'paid') {
            throw new RuntimeException('Solo se puede confirmar la entrega de un pedido pagado.', 409);
        }

        $key = 'order_' . $orderId . ':tickets:email:manual-confirmed';
        $this->pdo->prepare(
            'INSERT INTO email_deliveries (order_id, idempotency_key, recipient_email, subject, body, status, document_version, sent_at, created_at, updated_at)
             VALUES (?, ?, ?, "Confirmación histórica de entrega", "Correo confirmado manualmente desde administración; no se ha enviado ningún mensaje nuevo.", "sent", "manual", NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = "sent", error_message = NULL, sent_at = COALESCE(sent_at, NOW()), updated_at = NOW()'
        )->execute([$orderId, $key, (string) $order['email']]);
        $this->auditAdminOperation($operator, 'ticket_email_delivery_confirmed', $orderId, ['source' => 'historical_manual_confirmation']);

        return $this->adminOrderById($orderId);
    }

    /** Creates an admin-only sandbox order. It never reserves production capacity. */
    public function createTestOrder(int $eventId, array $data): array
    {
        $this->redsys->assertSandboxConfigured();
        require_fields($data, ['first_name', 'last_name', 'email', 'phone', 'items']);
        if (empty($data['privacy_accepted']) || empty($data['terms_accepted']) || !is_array($data['items'])) {
            throw new RuntimeException('Completa los datos y acepta las condiciones para continuar.');
        }
        if (empty($data['age_requirement_accepted']) || empty($data['dress_code_accepted'])) {
            throw new RuntimeException('Confirma las condiciones de acceso para continuar.');
        }
        $event = $this->requireAdminEvent($eventId);
        $paymentMethod = $this->redsys->paymentMethod((string) ($data['payment_method'] ?? 'card'));
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
                'payment' => ['sandbox' => true] + $this->redsysForm((string) $existingOrder['redsys_order'], (int) $existingOrder['total_cents'], $event, (string) $existingOrder['public_token'], $paymentMethod),
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
                 (public_token, redsys_order, first_name, last_name, name, email, phone, age_requirement_accepted, age_requirement_accepted_at, dress_code_accepted, dress_code_accepted_at, dress_code_version, subtotal_cents, total_cents, currency, status, reservation_expires_at, ip_address, user_agent, is_test, environment, order_status, payment_status, delivery_status, test_session_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), 1, NOW(), ?, 0, 0, ?, "payment_processing", ?, ?, ?, 1, "sandbox", "pending_payment", "pending", "pending", ?, NOW(), NOW())'
            );
            $orderStmt->execute([$publicToken, $redsysOrder, $firstName, $lastName, $name, $email, $phone, self::DRESS_CODE_VERSION, env_value('REDSYS_CURRENCY', '978'), $expires, client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $testSession]);
            $orderId = (int) $this->pdo->lastInsertId();
            $reference = 'TEST-PG' . str_pad((string) $eventId, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE ticket_orders SET test_reference = ? WHERE id = ?')->execute([$reference, $orderId]);

            $subtotal = 0;
            $quantityTotal = 0;
            $pricedItems = [];
            $orderItems = [];
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
                $subtotal += $lineTotal;
                $quantityTotal += $quantity;
                $referenceUnitPrice = $this->visibleReferencePrice($type, $unitPrice);
                $pricedItems[] = ['ticket_type_id' => $typeId, 'quantity' => $quantity, 'unit_price_cents' => $unitPrice];
                $this->pdo->prepare(
                    'INSERT INTO ticket_order_items (order_id, event_id, ticket_type_id, ticket_type_name, quantity, unit_price_cents, reference_unit_price_cents, reference_total_cents, promotional_label, show_reference_price, total_cents, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                )->execute([$orderId, $eventId, $typeId, $type['name'], $quantity, $unitPrice, $referenceUnitPrice, $referenceUnitPrice ? $quantity * $referenceUnitPrice : null, $this->promotionalLabel($type), $referenceUnitPrice ? 1 : 0, $lineTotal]);
                $orderItems[] = ['id' => (int) $this->pdo->lastInsertId(), 'quantity' => $quantity];
            }
            if ($quantityTotal === 0) {
                throw new RuntimeException('Selecciona al menos una entrada para la prueba.');
            }
            $this->persistAttendees(
                $orderId,
                $orderItems,
                $this->normaliseAttendees($data['attendees'] ?? null, $quantityTotal, $name)
            );
            $discount = $this->discounts()->quote(
                (string) ($data['discount_code'] ?? ''),
                $eventId,
                $pricedItems,
                $email,
                $phone,
                true
            );
            $total = (int) $discount['total_cents'];
            $this->pdo->prepare(
                'UPDATE ticket_orders
                 SET subtotal_cents = ?, discount_code_id = ?, discount_code = ?, discount_type = ?, discount_value_snapshot = ?, discount_amount_cents = ?, discount_snapshot = ?, discount_applied_at = ?, total_cents = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $subtotal,
                $discount['code_id'] ?? null,
                $discount['code'] ?? null,
                $discount['type'] ?? null,
                $discount['value_label'] ?? null,
                (int) $discount['discount_cents'],
                !empty($discount['snapshot']) ? json_encode($discount['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                !empty($discount['applied']) ? now_mysql() : null,
                $total,
                $orderId,
            ]);
            $this->discounts()->reserve($discount, $orderId, $eventId, $email, $phone, $expires);
            $this->pdo->prepare(
                'INSERT INTO payment_attempts (order_id, redsys_order, environment, amount_cents, currency, signature_version, payment_method, status, created_at, updated_at)
                 VALUES (?, ?, "test", ?, ?, ?, ?, "created", NOW(), NOW())'
            )->execute([$orderId, $redsysOrder, $total, env_value('REDSYS_CURRENCY', '978'), env_value('REDSYS_SIGNATURE_VERSION', 'HMAC_SHA256_V1'), $paymentMethod]);
            $this->pdo->commit();

            return [
                'order' => $this->getOrderByToken($publicToken),
                'payment' => ['sandbox' => true] + $this->redsysForm($redsysOrder, $total, $event, $publicToken, $paymentMethod),
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
                    $this->discounts()->consumeForOrder((int) $order['id']);
                } else {
                    $this->discounts()->releaseForOrder((int) $order['id'], 'cancelled');
                }
            }

            $this->pdo->commit();
            if ($accepted && $order['status'] !== 'paid') {
                // Solo el callback firmado y validado puede dejar una tarea fiscal.
                // No hay comunicación remota con Holded en esta ruta crítica.
                (new HoldedSyncService($this->pdo, new HoldedClient()))->queuePaidProductionOrder((int) $order['id']);
                if ($isTestOrder) {
                    $this->sendTestConfirmation((int) $order['id']);
                } else {
                    $this->sendConfirmation((int) $order['id']);
                }
            }
            error_log('Perigallo Redsys notification processed: order=' . $orderNumber . ' attempt=' . $attempt['id'] . ' method=' . ($attempt['payment_method'] ?? 'card') . ' response=' . $responseCode . ' accepted=' . ($accepted ? '1' : '0'));
            return ['ok' => true, 'accepted' => $accepted, 'order' => $orderNumber];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function adminSummary(): array
    {
        $orders = $this->pdo->query('SELECT status, COUNT(*) AS total, COALESCE(SUM(total_cents),0) AS amount FROM ticket_orders WHERE is_test = 0 GROUP BY status')->fetchAll();
        $holded = $this->pdo->query('SELECT holded_status, COUNT(*) AS total FROM ticket_orders WHERE is_test = 0 GROUP BY holded_status')->fetchAll();
        $events = $this->adminListEvents();
        $latestOrders = $this->adminOrders(6);
        return ['orders' => $orders, 'events' => $events, 'latest_orders' => $latestOrders, 'holded' => $holded];
    }

    public function adminDiscountCodes(array $filters = []): array
    {
        return $this->discounts()->list($filters);
    }

    public function adminDiscountCode(int $id): array
    {
        return $this->discounts()->get($id);
    }

    public function adminSaveDiscountCode(array $data, string $operator, ?int $id = null): array
    {
        $code = $this->discounts()->save($data, $operator, $id);
        $this->auditDiscountOperation($operator, $id === null ? 'discount_code_created' : 'discount_code_updated', (int) $code['id'], ['code' => $code['code']]);
        return $code;
    }

    public function adminDuplicateDiscountCode(int $id, string $operator): array
    {
        $code = $this->discounts()->duplicate($id, $operator);
        $this->auditDiscountOperation($operator, 'discount_code_duplicated', (int) $code['id'], ['source_id' => $id, 'code' => $code['code']]);
        return $code;
    }

    public function adminArchiveDiscountCode(int $id, string $operator): array
    {
        $code = $this->discounts()->archive($id, $operator);
        $this->auditDiscountOperation($operator, 'discount_code_archived', (int) $code['id'], ['code' => $code['code']]);
        return $code;
    }

    public function adminDeleteUnusedDiscountCode(int $id, string $operator): array
    {
        $result = $this->discounts()->deleteUnused($id);
        $this->auditDiscountOperation($operator, 'discount_code_deleted', $id, ['code' => $result['code']]);
        return $result;
    }

    public function adminDiscountCodeHistory(int $id): array
    {
        return $this->discounts()->usageHistory($id);
    }

    public function adminDiscountCodeMeta(): array
    {
        $events = $this->pdo->query('SELECT id, title, starts_at, status FROM events WHERE status <> "archived" ORDER BY starts_at DESC, id DESC')->fetchAll();
        $types = $this->pdo->query('SELECT tt.id, tt.event_id, tt.name, e.title AS event_title FROM ticket_types tt JOIN events e ON e.id = tt.event_id WHERE tt.status <> "archived" AND e.status <> "archived" ORDER BY e.starts_at DESC, tt.sort_order ASC, tt.id ASC')->fetchAll();
        return ['events' => $events, 'ticket_types' => $types];
    }

    /** Datos de venta disponibles solo para la taquilla interna. */
    public function adminCashOrderMeta(): array
    {
        $events = $this->pdo->query('SELECT id, title, starts_at, status FROM events WHERE status <> "archived" ORDER BY starts_at ASC, id ASC')->fetchAll();
        // La visibilidad y el estado de venta online no deben impedir una venta
        // registrada por el equipo. Por ejemplo, una entrada con la venta web
        // cerrada sigue teniendo cupo que se puede asignar en efectivo.
        // Los borradores se excluyen para no poder emitir por error una entrada
        // que todavía no se ha terminado de configurar.
        $types = $this->pdo->query('SELECT * FROM ticket_types WHERE status NOT IN ("draft", "archived") ORDER BY event_id ASC, sort_order ASC, id ASC')->fetchAll();
        $byEvent = [];
        foreach ($types as $type) {
            $type = $this->adminTicketTypeRow($type);
            $byEvent[(int) $type['event_id']][] = $type;
        }
        foreach ($events as &$event) {
            $event['id'] = (int) $event['id'];
            $event['ticket_types'] = $byEvent[(int) $event['id']] ?? [];
        }
        unset($event);
        return ['events' => $events];
    }

    /** Crea una venta o reserva de efectivo sin exponer ninguna ruta pública de compra. */
    public function adminCreateCashOrder(array $data, string $operator): array
    {
        $this->requireCashOrderSchema();
        $eventId = (int) ($data['event_id'] ?? 0);
        if ($eventId <= 0 || !is_array($data['items'] ?? null)) {
            throw new InvalidArgumentException('Selecciona un evento y al menos una entrada.');
        }
        $firstName = clean_string((string) ($data['first_name'] ?? ''), 120);
        $lastName = clean_string((string) ($data['last_name'] ?? ''), 160);
        $name = trim($firstName . ' ' . $lastName);
        $email = mb_strtolower(clean_string((string) ($data['email'] ?? ''), 190));
        $phone = clean_string((string) ($data['phone'] ?? ''), 60);
        if ($name === '' || $phone === '') {
            throw new InvalidArgumentException('Indica nombre y teléfono de WhatsApp para enviar las entradas.');
        }
        $cashStatus = (string) ($data['cash_payment_status'] ?? 'reserved');
        if (!in_array($cashStatus, ['reserved', 'paid'], true)) {
            throw new InvalidArgumentException('El estado del pago en efectivo no es válido.');
        }
        $inventoryMode = (string) ($data['inventory_mode'] ?? 'cash');
        if (!in_array($inventoryMode, ['cash', 'manual_reserve'], true)) {
            throw new InvalidArgumentException('El tipo de emisión manual no es válido.');
        }
        $this->requireManualReserveInventorySchema();
        $notes = clean_string((string) ($data['cash_payment_notes'] ?? ''), 1000);
        $cashDiscountCents = $this->cashDiscountCents($data['cash_discount_euros'] ?? 0);
        $reservationExpiresAt = $cashStatus === 'reserved' ? $this->cashReservationExpiry((string) ($data['reservation_expires_at'] ?? '')) : null;

        $this->pdo->beginTransaction();
        try {
            $event = $this->requireAdminEvent($eventId);
            $orderStmt = $this->pdo->prepare(
                'INSERT INTO ticket_orders
                 (public_token, redsys_order, first_name, last_name, name, email, phone, age_requirement_accepted, age_requirement_accepted_at, dress_code_accepted, dress_code_accepted_at, dress_code_version, subtotal_cents, total_cents, currency, status, reservation_expires_at, ip_address, user_agent, is_test, environment, sales_channel, inventory_mode, cash_payment_status, cash_payment_notes, cash_payment_recorded_by, cash_payment_recorded_at, order_status, payment_status, delivery_status, paid_at, holded_status, holded_excluded, holded_exclusion_reason, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), 1, NOW(), ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, "cash", ?, ?, ?, ?, ?, ?, ?, "generated", ?, "not_required", 1, "cash_sale", NOW(), NOW())'
            );
            $isPaid = $cashStatus === 'paid';
            $publicToken = public_token();
            $orderStmt->execute([
                $publicToken, $this->nextRedsysOrder(), $firstName, $lastName, $name, $email, $phone, self::DRESS_CODE_VERSION,
                env_value('REDSYS_CURRENCY', '978'), $isPaid ? 'paid' : 'pending', $reservationExpiresAt, client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                !empty($event['is_test']) ? 1 : 0, !empty($event['is_test']) ? 'sandbox' : 'production',
                $inventoryMode === 'manual_reserve' ? 'manual_reserve' : 'standard', $cashStatus, $notes ?: null, $isPaid ? $operator : null, $isPaid ? now_mysql() : null, $isPaid ? 'confirmed' : 'pending_payment', $isPaid ? 'paid' : 'pending', $isPaid ? now_mysql() : null,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();
            $subtotal = 0;
            $quantityTotal = 0;
            $orderItems = [];
            foreach ($data['items'] as $item) {
                $typeId = (int) ($item['ticket_type_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($typeId <= 0 || $quantity <= 0) continue;
                $type = $this->lockCashTicketType($typeId, $eventId);
                if (!$type) throw new RuntimeException('Tipo de entrada no disponible para venta interna.');
                if ($quantity > (int) $type['max_per_order']) throw new RuntimeException('Cantidad no permitida para ' . $type['name'] . '.');
                $available = $inventoryMode === 'manual_reserve'
                    ? $this->manualReserveAvailableForType($type)
                    : $this->standardAvailableForType($type);
                if ($quantity > $available) {
                    throw new RuntimeException($inventoryMode === 'manual_reserve'
                        ? 'No quedan suficientes plazas del cupo manual para ' . $type['name'] . '.'
                        : 'No quedan suficientes entradas para ' . $type['name'] . '.');
                }
                $unitBase = (int) $type['price_cents'];
                $taxRate = max(0, (float) ($type['tax_rate'] ?? 0));
                $unitTax = (int) round($unitBase * $taxRate / 100);
                $unitFee = (int) ($type['fee_cents'] ?? 0);
                $unitPrice = $unitBase + $unitTax + $unitFee;
                $referenceUnitPrice = $this->visibleReferencePrice($type, $unitPrice);
                $lineTotal = $quantity * $unitPrice;
                $orderItems[] = [
                    'id' => $this->insertOrderItem(
                        $orderId,
                        $eventId,
                        $typeId,
                        (string) $type['name'],
                        $quantity,
                        $unitPrice,
                        $unitBase,
                        $unitTax,
                        $taxRate,
                        $unitFee,
                        $referenceUnitPrice,
                        $lineTotal,
                        $this->promotionalLabel($type)
                    ),
                    'quantity' => $quantity,
                ];
                $subtotal += $lineTotal;
                $quantityTotal += $quantity;
            }
            if ($quantityTotal === 0) throw new InvalidArgumentException('Selecciona al menos una entrada.');
            if ($cashDiscountCents > $subtotal) {
                throw new InvalidArgumentException('El descuento no puede superar el total de las entradas.');
            }
            $total = $subtotal - $cashDiscountCents;
            $this->persistCashAttendees($orderId, $orderItems, $name);
            $this->pdo->prepare('UPDATE ticket_orders SET subtotal_cents = ?, discount_type = ?, discount_value_snapshot = ?, discount_amount_cents = ?, discount_snapshot = ?, discount_applied_at = ?, total_cents = ?, updated_at = NOW() WHERE id = ?')->execute([
                $subtotal,
                $cashDiscountCents > 0 ? 'fixed' : null,
                $cashDiscountCents > 0 ? 'Descuento manual en efectivo' : null,
                $cashDiscountCents,
                $cashDiscountCents > 0 ? json_encode(['source' => 'cash_manual', 'amount_cents' => $cashDiscountCents, 'operator' => $operator], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $cashDiscountCents > 0 ? now_mysql() : null,
                $total,
                $orderId,
            ]);
            $this->generateTicketsOnce($orderId, $isPaid ? 'issued' : 'blocked');
            $this->auditAdminOperation($operator, $isPaid ? 'cash_order_created_paid' : 'cash_order_reserved', $orderId, ['event_id' => $eventId, 'quantity' => $quantityTotal, 'cash_discount_cents' => $cashDiscountCents, 'inventory_mode' => $inventoryMode, 'notes' => $notes]);
            $this->pdo->commit();
            return ['order' => $this->adminOrderById($orderId), 'whatsapp_url' => $this->cashOrderWhatsAppUrl($phone, $name, (string) $event['title'], $publicToken)];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function adminRecordCashPayment(int $orderId, array $data, string $operator): array
    {
        $this->requireCashOrderSchema();
        $notes = clean_string((string) ($data['cash_payment_notes'] ?? ''), 1000);
        $this->pdo->beginTransaction();
        try {
            $order = $this->lockedOrder($orderId);
            if (($order['sales_channel'] ?? 'web') !== 'cash') throw new RuntimeException('Este pedido no pertenece a la operativa de efectivo.');
            if (in_array((string) $order['status'], ['cancelled', 'refunded'], true)) throw new RuntimeException('No se puede registrar un cobro en un pedido cancelado o devuelto.');
            if (($order['cash_payment_status'] ?? '') === 'paid') throw new RuntimeException('Este pedido ya figura como cobrado en efectivo.');
            if (!empty($order['reservation_expires_at']) && strtotime((string) $order['reservation_expires_at']) <= time()) {
                $items = $this->pdo->prepare('SELECT ticket_type_id, event_id, quantity FROM ticket_order_items WHERE order_id = ?');
                $items->execute([$orderId]);
                foreach ($items->fetchAll() as $item) {
                    $type = $this->lockCashTicketType((int) $item['ticket_type_id'], (int) $item['event_id']);
                    $available = $type && (($order['inventory_mode'] ?? 'standard') === 'manual_reserve')
                        ? $this->manualReserveAvailableForType($type)
                        : ($type ? $this->standardAvailableForType($type) : 0);
                    if (!$type || (int) $item['quantity'] > $available) throw new RuntimeException('La reserva ha caducado y ya no hay aforo suficiente para confirmarla.');
                }
            }
            $this->pdo->prepare('UPDATE ticket_orders SET status = "paid", order_status = "confirmed", payment_status = "paid", cash_payment_status = "paid", cash_payment_notes = ?, cash_payment_recorded_by = ?, cash_payment_recorded_at = NOW(), reservation_expires_at = NULL, delivery_status = "generated", paid_at = NOW(), holded_status = "not_required", holded_excluded = 1, holded_exclusion_reason = "cash_sale", holded_next_attempt_at = NULL, holded_last_error = NULL, updated_at = NOW() WHERE id = ?')->execute([$notes ?: ($order['cash_payment_notes'] ?? null), $operator, $orderId]);
            $this->pdo->prepare('UPDATE tickets t JOIN ticket_order_items oi ON oi.id = t.order_item_id SET t.status = "issued", t.updated_at = NOW() WHERE oi.order_id = ? AND t.status = "blocked"')->execute([$orderId]);
            $this->auditAdminOperation($operator, 'cash_payment_recorded', $orderId, ['notes' => $notes]);
            $this->pdo->commit();
            return $this->adminOrderById($orderId);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function adminSendCashOrder(int $orderId, string $operator): array
    {
        $this->requireCashOrderSchema();
        $order = $this->lockedOrder($orderId);
        if (($order['sales_channel'] ?? 'web') !== 'cash') throw new RuntimeException('Este pedido no pertenece a la operativa de efectivo.');
        if (($order['cash_payment_status'] ?? '') === 'paid') {
            $this->sendConfirmation($orderId);
        } else {
            $link = app_base_url() . '/entradas/pedido/?token=' . rawurlencode((string) $order['public_token']);
            $subject = 'Reserva pendiente de pago · Perigallo';
            $body = "Hola {$order['name']},\n\nHemos reservado tus entradas para pago en efectivo. La reserva queda pendiente de confirmar el cobro.\n\nConsulta los datos de tu reserva: {$link}\n\nEquipo Perigallo\n";
            $result = $this->mailer->queueOrderEmail($this->pdo, $orderId, (string) $order['email'], $subject, $body);
            $this->pdo->prepare('UPDATE ticket_orders SET delivery_status = ?, updated_at = NOW() WHERE id = ?')->execute([$result === 'sent' ? 'sent' : 'failed', $orderId]);
        }
        $this->auditAdminOperation($operator, 'cash_order_sent', $orderId, []);
        return $this->adminOrderById($orderId);
    }

    public function adminOrders(int $limit = 200): array
    {
        $limit = max(1, min(200, $limit));
        $cashColumns = $this->cashOrderSchemaAvailable()
            ? 'o.sales_channel, o.cash_payment_status, o.cash_payment_notes, o.cash_payment_recorded_by, o.cash_payment_recorded_at'
            : '"web" AS sales_channel, "not_applicable" AS cash_payment_status, NULL AS cash_payment_notes, NULL AS cash_payment_recorded_by, NULL AS cash_payment_recorded_at';
        $paymentMethod = $this->cashOrderSchemaAvailable()
            ? 'CASE WHEN o.sales_channel = "cash" THEN "cash" ELSE COALESCE((SELECT pa.payment_method FROM payment_attempts pa WHERE pa.order_id = o.id ORDER BY pa.id DESC LIMIT 1), "card") END'
            : 'COALESCE((SELECT pa.payment_method FROM payment_attempts pa WHERE pa.order_id = o.id ORDER BY pa.id DESC LIMIT 1), "card")';
        return $this->pdo->query(
            'SELECT o.id, o.public_token, o.redsys_order, o.test_reference, o.is_test, o.environment,
                    o.order_status, o.payment_status, o.delivery_status, ' . $cashColumns . ', o.name, o.email, o.phone, o.whatsapp_consent,
                    (SELECT ed.status FROM email_deliveries ed WHERE ed.order_id = o.id AND ed.idempotency_key IS NOT NULL ORDER BY ed.id DESC LIMIT 1) AS email_delivery_status,
                    (SELECT ed.sent_at FROM email_deliveries ed WHERE ed.order_id = o.id AND ed.idempotency_key IS NOT NULL ORDER BY ed.id DESC LIMIT 1) AS email_delivery_at,
                    (SELECT ed.error_message FROM email_deliveries ed WHERE ed.order_id = o.id AND ed.idempotency_key IS NOT NULL ORDER BY ed.id DESC LIMIT 1) AS email_delivery_error,
                    (SELECT dl.status FROM ticket_delivery_logs dl WHERE dl.order_id = o.id AND dl.channel = "whatsapp" AND dl.idempotency_key IS NOT NULL ORDER BY dl.id DESC LIMIT 1) AS whatsapp_delivery_status,
                    (SELECT dl.recipient FROM ticket_delivery_logs dl WHERE dl.order_id = o.id AND dl.channel = "whatsapp" AND dl.idempotency_key IS NOT NULL ORDER BY dl.id DESC LIMIT 1) AS whatsapp_recipient,
                    (SELECT dl.template_name FROM ticket_delivery_logs dl WHERE dl.order_id = o.id AND dl.channel = "whatsapp" AND dl.idempotency_key IS NOT NULL ORDER BY dl.id DESC LIMIT 1) AS whatsapp_template_name,
                    (SELECT dl.provider_message_id FROM ticket_delivery_logs dl WHERE dl.order_id = o.id AND dl.channel = "whatsapp" AND dl.idempotency_key IS NOT NULL ORDER BY dl.id DESC LIMIT 1) AS whatsapp_message_id,
                    (SELECT dl.last_status_at FROM ticket_delivery_logs dl WHERE dl.order_id = o.id AND dl.channel = "whatsapp" AND dl.idempotency_key IS NOT NULL ORDER BY dl.id DESC LIMIT 1) AS whatsapp_delivery_at,
                    (SELECT dl.error_message FROM ticket_delivery_logs dl WHERE dl.order_id = o.id AND dl.channel = "whatsapp" AND dl.idempotency_key IS NOT NULL ORDER BY dl.id DESC LIMIT 1) AS whatsapp_delivery_error,
                    o.subtotal_cents, o.discount_code, o.discount_amount_cents, o.total_cents, o.status, o.reservation_expires_at, o.paid_at, o.created_at,
                    o.billing_requested, o.holded_status, o.holded_document_type, o.holded_document_id, o.holded_document_number, o.holded_payment_id,
                    o.holded_sync_attempts, o.holded_synced_at, o.holded_last_error, o.holded_next_attempt_at,
                    CASE WHEN o.status IN ("cancelled", "refunded") THEN o.status ELSE COALESCE(o.payment_status, o.status) END AS display_status,
                    ' . $paymentMethod . ' AS payment_method,
                    COALESCE(items.ticket_quantity, 0) AS ticket_quantity,
                    COALESCE(attendee_data.attendee_count, 0) AS attendee_count,
                    COALESCE(attendee_data.allergy_attendee_count, 0) AS allergy_attendee_count,
                    COALESCE(attendee_data.severe_allergy_count, 0) AS severe_allergy_count,
                    items.event_title
             FROM ticket_orders o
             LEFT JOIN (
                SELECT oi.order_id, SUM(oi.quantity) AS ticket_quantity,
                       GROUP_CONCAT(DISTINCT e.title ORDER BY e.title SEPARATOR " · ") AS event_title
                FROM ticket_order_items oi
                LEFT JOIN events e ON e.id = oi.event_id
                GROUP BY oi.order_id
             ) items ON items.order_id = o.id
             LEFT JOIN (
                SELECT ta.order_id,
                       COUNT(*) AS attendee_count,
                       SUM(CASE WHEN ta.has_allergies = 1 THEN 1 ELSE 0 END) AS allergy_attendee_count,
                       SUM(CASE WHEN ta.severe_allergy = 1 THEN 1 ELSE 0 END) AS severe_allergy_count
                FROM ticket_attendees ta
                GROUP BY ta.order_id
             ) attendee_data ON attendee_data.order_id = o.id
             ORDER BY o.id DESC
             LIMIT ' . $limit
        )->fetchAll();
    }

    public function adminOrderAttendees(int $orderId): array
    {
        $order = $this->pdo->prepare('SELECT id, name, redsys_order, test_reference FROM ticket_orders WHERE id = ? LIMIT 1');
        $order->execute([$orderId]);
        $record = $order->fetch();
        if (!$record) {
            throw new InvalidArgumentException('Pedido no encontrado.');
        }
        $dietaryFields = $this->ticketAttendeeDietarySchemaAvailable()
            ? 'ta.dietary_preference, ta.dietary_notes'
            : 'NULL AS dietary_preference, NULL AS dietary_notes';
        $dietaryGroupBy = $this->ticketAttendeeDietarySchemaAvailable()
            ? ', ta.dietary_preference, ta.dietary_notes'
            : '';
        $attendees = $this->pdo->prepare(
            'SELECT ta.attendee_name, ta.has_allergies, ta.severe_allergy, ta.allergy_notes, ' . $dietaryFields . ', ta.ticket_sequence,
                    toi.ticket_type_name, t.public_code,
                    GROUP_CONCAT(taa.allergen_label ORDER BY taa.allergen_label SEPARATOR " · ") AS allergens
             FROM ticket_attendees ta
             JOIN ticket_order_items toi ON toi.id = ta.order_item_id
             LEFT JOIN tickets t ON t.id = ta.ticket_id
             LEFT JOIN ticket_attendee_allergens taa ON taa.attendee_id = ta.id
             WHERE ta.order_id = ?
             GROUP BY ta.id, ta.attendee_name, ta.has_allergies, ta.severe_allergy, ta.allergy_notes' . $dietaryGroupBy . ', ta.ticket_sequence, toi.ticket_type_name, t.public_code
             ORDER BY toi.id ASC, ta.ticket_sequence ASC'
        );
        $attendees->execute([$orderId]);
        return [
            'order' => [
                'id' => (int) $record['id'],
                'name' => $record['name'],
                'reference' => $record['test_reference'] ?: $record['redsys_order'],
            ],
            'attendees' => $attendees->fetchAll(),
        ];
    }

    public function adminCancelOrder(int $orderId, string $operator, string $reason = ''): array
    {
        return $this->changeOrderCommercialStatus($orderId, $operator, 'cancelled', $reason);
    }

    public function adminRecordRefund(int $orderId, string $operator, string $reason = ''): array
    {
        return $this->changeOrderCommercialStatus($orderId, $operator, 'refunded', $reason);
    }

    public function adminPurgeTestOrder(int $orderId, string $operator): void
    {
        $this->purgeDiscardableOrder($orderId, $operator, true, 'test_order_deleted');
    }

    /** Purga un pedido técnico o una invitación sin importe al eliminar su evento de prueba. */
    private function purgeDiscardableOrder(int $orderId, string $operator, bool $requireTestOrder, string $auditAction, bool $allowPaidOrder = false): void
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->lockedOrder($orderId);
            if ($requireTestOrder && empty($order['is_test'])) {
                throw new RuntimeException('Solo se pueden eliminar pedidos de prueba.');
            }
            if (!$requireTestOrder && !$allowPaidOrder && empty($order['is_test']) && (int) ($order['total_cents'] ?? 0) > 0) {
                throw new RuntimeException('No se puede eliminar un pedido con cobro real.');
            }
            $ticketStatement = $this->pdo->prepare('SELECT t.id FROM tickets t JOIN ticket_order_items oi ON oi.id = t.order_item_id WHERE oi.order_id = ?');
            $ticketStatement->execute([$orderId]);
            $ticketIds = array_map('intval', array_column($ticketStatement->fetchAll(), 'id'));
            if ($ticketIds) {
                $marks = implode(',', array_fill(0, count($ticketIds), '?'));
                $movementStatement = $this->pdo->prepare('SELECT id FROM ticket_access_movements WHERE ticket_id IN (' . $marks . ')');
                $movementStatement->execute($ticketIds);
                $movementIds = array_map('intval', array_column($movementStatement->fetchAll(), 'id'));
                if ($movementIds) {
                    $movementMarks = implode(',', array_fill(0, count($movementIds), '?'));
                    $this->pdo->prepare('UPDATE ticket_access_movements SET reversal_of_id = NULL WHERE reversal_of_id IN (' . $movementMarks . ')')->execute($movementIds);
                    $this->pdo->prepare('DELETE FROM ticket_access_movements WHERE id IN (' . $movementMarks . ')')->execute($movementIds);
                }
                $this->pdo->prepare('UPDATE ticket_scans SET ticket_id = NULL WHERE ticket_id IN (' . $marks . ')')->execute($ticketIds);
                $this->pdo->prepare('DELETE FROM tickets WHERE id IN (' . $marks . ')')->execute($ticketIds);
            }
            $this->pdo->prepare('DELETE FROM email_deliveries WHERE order_id = ?')->execute([$orderId]);
            $this->pdo->prepare('DELETE FROM discount_code_usages WHERE order_id = ?')->execute([$orderId]);
            $this->pdo->prepare('DELETE FROM payment_attempts WHERE order_id = ?')->execute([$orderId]);
            $this->deleteOrderReferencesIfTableExists('holded_refund_requests', $orderId);
            $this->deleteOrderReferencesIfTableExists('holded_sync_logs', $orderId);
            $this->pdo->prepare('DELETE FROM ticket_order_items WHERE order_id = ?')->execute([$orderId]);
            $this->pdo->prepare('DELETE FROM ticket_orders WHERE id = ?')->execute([$orderId]);
            $this->auditAdminOperation($operator, $auditAction, $orderId, ['reference' => $order['test_reference'] ?: $order['redsys_order']]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Algunas instalaciones aún no tienen el módulo Holded; su ausencia no debe bloquear una prueba. */
    private function deleteOrderReferencesIfTableExists(string $table, int $orderId): void
    {
        if (!in_array($table, ['holded_refund_requests', 'holded_sync_logs'], true)) {
            throw new InvalidArgumentException('Tabla de referencias no permitida.');
        }
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $exists->execute([$table]);
        if ((int) $exists->fetchColumn() === 0) {
            return;
        }
        $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE order_id = ?')->execute([$orderId]);
    }

    private function changeOrderCommercialStatus(int $orderId, string $operator, string $targetStatus, string $reason): array
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->lockedOrder($orderId);
            $currentStatus = (string) ($order['status'] ?? '');
            if (in_array($currentStatus, ['cancelled', 'refunded'], true)) {
                throw new RuntimeException('Este pedido ya está ' . ($currentStatus === 'refunded' ? 'reembolsado' : 'cancelado') . '.');
            }
            if ($targetStatus === 'refunded' && !in_array((string) ($order['payment_status'] ?? $currentStatus), ['paid', 'cancelled'], true) && $currentStatus !== 'paid') {
                throw new RuntimeException('Solo se puede registrar una devolución sobre un pedido que haya sido cobrado.');
            }
            $paymentStatus = $targetStatus === 'refunded' ? 'refunded' : (string) ($order['payment_status'] ?? 'pending');
            $this->pdo->prepare('UPDATE ticket_orders SET status = ?, order_status = "cancelled", payment_status = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$targetStatus, $paymentStatus, $orderId]);
            $ticketStatus = $targetStatus === 'refunded' ? 'refunded' : 'cancelled';
            $this->pdo->prepare('UPDATE tickets t JOIN ticket_order_items oi ON oi.id = t.order_item_id SET t.status = ?, t.updated_at = NOW() WHERE oi.order_id = ?')
                ->execute([$ticketStatus, $orderId]);
            $this->discounts()->releaseForOrder($orderId, $targetStatus);
            $this->auditAdminOperation($operator, $targetStatus === 'refunded' ? 'order_refund_recorded' : 'order_cancelled', $orderId, [
                'reason' => clean_string($reason, 500),
                'payment_status_preserved' => $targetStatus === 'cancelled' ? $paymentStatus : null,
            ]);
            $this->pdo->commit();
            return $this->adminOrderById($orderId);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function lockedOrder(int $orderId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ticket_orders WHERE id = ? FOR UPDATE');
        $statement->execute([$orderId]);
        $order = $statement->fetch();
        if (!$order) {
            throw new RuntimeException('Pedido no encontrado.');
        }
        return $order;
    }

    private function adminOrderById(int $orderId): array
    {
        $cashColumns = $this->cashOrderSchemaAvailable()
            ? 'o.sales_channel, o.cash_payment_status, o.cash_payment_notes, o.cash_payment_recorded_by, o.cash_payment_recorded_at'
            : '"web" AS sales_channel, "not_applicable" AS cash_payment_status, NULL AS cash_payment_notes, NULL AS cash_payment_recorded_by, NULL AS cash_payment_recorded_at';
        $paymentMethod = $this->cashOrderSchemaAvailable()
            ? 'CASE WHEN o.sales_channel = "cash" THEN "cash" ELSE COALESCE((SELECT pa.payment_method FROM payment_attempts pa WHERE pa.order_id = o.id ORDER BY pa.id DESC LIMIT 1), "card") END'
            : 'COALESCE((SELECT pa.payment_method FROM payment_attempts pa WHERE pa.order_id = o.id ORDER BY pa.id DESC LIMIT 1), "card")';
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.public_token, o.redsys_order, o.test_reference, o.is_test, o.environment,
                    o.order_status, o.payment_status, o.delivery_status, ' . $cashColumns . ', o.name, o.email, o.phone, o.whatsapp_consent,
                    (SELECT ed.status FROM email_deliveries ed WHERE ed.order_id = o.id AND ed.idempotency_key IS NOT NULL ORDER BY ed.id DESC LIMIT 1) AS email_delivery_status,
                    (SELECT dl.status FROM ticket_delivery_logs dl WHERE dl.order_id = o.id AND dl.channel = "whatsapp" AND dl.idempotency_key IS NOT NULL ORDER BY dl.id DESC LIMIT 1) AS whatsapp_delivery_status,
                    (SELECT dl.recipient FROM ticket_delivery_logs dl WHERE dl.order_id = o.id AND dl.channel = "whatsapp" AND dl.idempotency_key IS NOT NULL ORDER BY dl.id DESC LIMIT 1) AS whatsapp_recipient,
                    o.total_cents, o.status, o.reservation_expires_at, o.paid_at, o.created_at,
                    CASE WHEN o.status IN ("cancelled", "refunded") THEN o.status ELSE COALESCE(o.payment_status, o.status) END AS display_status,
                    ' . $paymentMethod . ' AS payment_method,
                    COALESCE(items.ticket_quantity, 0) AS ticket_quantity, items.event_title
             FROM ticket_orders o
             LEFT JOIN (
                SELECT oi.order_id, SUM(oi.quantity) AS ticket_quantity,
                       GROUP_CONCAT(DISTINCT e.title ORDER BY e.title SEPARATOR " · ") AS event_title
                FROM ticket_order_items oi
                LEFT JOIN events e ON e.id = oi.event_id
                GROUP BY oi.order_id
             ) items ON items.order_id = o.id
             WHERE o.id = ? LIMIT 1'
        );
        $statement->execute([$orderId]);
        $order = $statement->fetch();
        if ($order) {
            return $order;
        }
        throw new RuntimeException('Pedido no encontrado.');
    }

    private function auditAdminOperation(string $operator, string $action, int $orderId, array $context): void
    {
        try {
            $statement = $this->pdo->prepare('INSERT INTO ticket_admin_audit_logs (actor, action, entity_type, entity_id, context_json, created_at) VALUES (?, ?, "ticket_order", ?, ?, NOW())');
            $statement->execute([$operator, $action, $orderId, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (\Throwable $error) {
            // La auditoría será activa tras ejecutar la migración 011. No bloquea una operación ya validada.
        }
    }

    private function auditDiscountOperation(string $operator, string $action, int $discountCodeId, array $context): void
    {
        try {
            $statement = $this->pdo->prepare('INSERT INTO ticket_admin_audit_logs (actor, action, entity_type, entity_id, context_json, created_at) VALUES (?, ?, "discount_code", ?, ?, NOW())');
            $statement->execute([$operator, $action, $discountCodeId, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (\Throwable $error) {
            // La trazabilidad no debe impedir guardar una campaña válida.
        }
    }

    private function auditEventOperation(string $operator, string $action, int $eventId, array $context): void
    {
        try {
            $statement = $this->pdo->prepare('INSERT INTO ticket_admin_audit_logs (actor, action, entity_type, entity_id, context_json, created_at) VALUES (?, ?, "event", ?, ?, NOW())');
            $statement->execute([$operator, $action, $eventId, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (\Throwable $error) {
            // La trazabilidad no debe impedir una eliminación ya validada.
        }
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
             (canonical_id, event_type, origin_app, source_updated_at, slug, title, subtitle, description, image_url, location, address, starts_at, ends_at, sale_starts_at, sale_ends_at, capacity, status, visible, is_test, promoter, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
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
            !empty($data['is_test']) ? 1 : 0,
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
             (event_id, name, description, price_cents, reference_price_cents, promotional_label, show_reference_price, capacity, manual_reserve_capacity, min_quantity, max_per_order, active, sort_order, tax_rate, fee_cents, sale_starts_at, sale_ends_at, status, visible, requires_promo, promo_code_hash, waitlist_enabled, refundable, terms_text, label_color, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $eventId,
            clean_string((string) $data['name'], 160),
            trim((string) ($data['description'] ?? '')),
            (int) $data['price_cents'],
            $this->referencePrice($data),
            $this->promotionalLabel($data),
            $this->shouldShowReferencePrice($data) ? 1 : 0,
            (int) $data['capacity'],
            max(0, (int) ($data['manual_reserve_capacity'] ?? 0)),
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
        $existingIsTest = !empty($existing['is_test']);
        $requestedIsTest = array_key_exists('is_test', $data) ? !empty($data['is_test']) : $existingIsTest;
        if ($requestedIsTest !== $existingIsTest) {
            $orderCount = $this->pdo->prepare('SELECT COUNT(DISTINCT order_id) FROM ticket_order_items WHERE event_id = ?');
            $orderCount->execute([$eventId]);
            if ((string) ($existing['status'] ?? '') !== 'draft' || !empty($existing['publication_at']) || (int) $orderCount->fetchColumn() > 0) {
                throw new RuntimeException('El modo prueba solo puede cambiarse mientras el evento sea un borrador que nunca se haya publicado ni tenga pedidos.');
            }
        }
        $merged = array_merge($existing, $data);
        $merged['is_test'] = $requestedIsTest;
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
              status=?, visible=?, is_test=?, promoter=?, publication_at=?, unlisted=?, link_only=?, show_sold_out=?, show_availability=?, show_price_from=?,
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
            $requestedIsTest ? 1 : 0,
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

    public function adminSetEventArchived(int $eventId, bool $archive): array
    {
        $this->requireAdminEvent($eventId);
        if ($archive) {
            $this->pdo->prepare('UPDATE events SET status="archived", visible=0, updated_at=NOW() WHERE id=?')->execute([$eventId]);
        }
        else {
            $this->pdo->prepare('UPDATE events SET status="draft", visible=0, updated_at=NOW() WHERE id=?')->execute([$eventId]);
        }
        return $this->adminGetEvent($eventId) ?? [];
    }

    /** Solo los eventos clasificados previamente como prueba pueden eliminarse definitivamente. */
    public function adminDeleteEvent(int $eventId, string $operator): array
    {
        $event = $this->requireAdminEvent($eventId);
        if (empty($event['is_test'])) {
            throw new RuntimeException('Este evento está protegido. Solo los eventos creados en modo prueba pueden eliminarse definitivamente; archívalo para conservar su historial.');
        }
        $orders = $this->pdo->prepare(
            'SELECT DISTINCT o.id, o.is_test, o.total_cents FROM ticket_orders o JOIN ticket_order_items oi ON oi.order_id = o.id WHERE oi.event_id = ? ORDER BY o.id ASC'
        );
        $orders->execute([$eventId]);
        $rows = $orders->fetchAll();
        foreach ($rows as $order) {
            $otherEventItems = $this->pdo->prepare('SELECT COUNT(*) FROM ticket_order_items WHERE order_id = ? AND event_id <> ?');
            $otherEventItems->execute([(int) $order['id'], $eventId]);
            if ((int) $otherEventItems->fetchColumn() > 0) {
                throw new RuntimeException('No se puede eliminar la prueba porque uno de sus pedidos contiene entradas de otro evento protegido.');
            }
            $this->purgeDiscardableOrder((int) $order['id'], $operator, false, 'test_event_order_deleted', true);
        }

        $this->pdo->beginTransaction();
        try {
            $remaining = $this->pdo->prepare('SELECT COUNT(*) FROM ticket_order_items WHERE event_id=?');
            $remaining->execute([$eventId]);
            if ((int) $remaining->fetchColumn() > 0) {
                throw new RuntimeException('No se puede eliminar el evento mientras conserve pedidos.');
            }
            $this->pdo->prepare('DELETE FROM ticket_scans WHERE event_id=?')->execute([$eventId]);
            $this->pdo->prepare('DELETE FROM discount_code_events WHERE event_id=?')->execute([$eventId]);
            $this->pdo->prepare('DELETE dct FROM discount_code_ticket_types dct JOIN ticket_types tt ON tt.id=dct.ticket_type_id WHERE tt.event_id=?')->execute([$eventId]);
            $this->pdo->prepare('DELETE FROM ticket_types WHERE event_id=?')->execute([$eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id=?')->execute([$eventId]);
            $this->auditEventOperation($operator, 'event_deleted', $eventId, ['purged_test_orders' => count($rows)]);
            $this->pdo->commit();
            return ['deleted' => true, 'purged_test_orders' => count($rows)];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function adminUpdateTicketType(int $eventId, int $ticketTypeId, array $data): array
    {
        $this->requireAdminEvent($eventId);
        $existing = $this->requireTicketType($eventId, $ticketTypeId);
        $merged = array_merge($existing, $data);
        $this->validateTicketType($merged);
        $capacity = max(0, (int) $merged['capacity']);
        $manualReserve = max(0, (int) ($merged['manual_reserve_capacity'] ?? 0));
        $metrics = $this->ticketMetrics($ticketTypeId);
        $manualCommitted = $metrics['manual_sold'] + $metrics['manual_reserved'];
        $standardCommitted = max(0, $metrics['sold'] + $metrics['reserved'] - $manualCommitted);
        if ($capacity < $standardCommitted) {
            throw new RuntimeException('No puedes reducir el cupo online por debajo de las entradas vendidas o reservadas.');
        }
        if ($manualReserve < $manualCommitted) {
            throw new RuntimeException('No puedes reducir el cupo manual por debajo de las entradas manuales ya emitidas o reservadas.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ticket_types SET name=?, description=?, price_cents=?, reference_price_cents=?, promotional_label=?, show_reference_price=?, capacity=?, manual_reserve_capacity=?, min_quantity=?, max_per_order=?, active=?, sort_order=?, tax_rate=?, fee_cents=?, sale_starts_at=?, sale_ends_at=?, status=?, visible=?, requires_promo=?, promo_code_hash=?, waitlist_enabled=?, refundable=?, terms_text=?, label_color=?, archived_at=?, updated_at=NOW() WHERE id=? AND event_id=?'
        );
        $status = $this->ticketStatus($merged);
        $stmt->execute([
            clean_string((string) $merged['name'], 160), trim((string) ($merged['description'] ?? '')), max(0, (int) $merged['price_cents']), $this->referencePrice($merged), $this->promotionalLabel($merged), $this->shouldShowReferencePrice($merged) ? 1 : 0, $capacity, $manualReserve,
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
        $dietaryFields = $this->ticketAttendeeDietarySchemaAvailable()
            ? 'ta.dietary_preference, ta.dietary_notes'
            : 'NULL AS dietary_preference, NULL AS dietary_notes';
        $rows = $this->pdo->prepare(
            'SELECT t.id, t.public_code, t.status, t.access_status, t.first_entry_at, t.last_entry_at, t.last_exit_at, t.entry_count, t.exit_count, t.last_access_action, t.last_access_by, toi.ticket_type_name,
                    COALESCE(ta.attendee_name, o.name) AS name, o.email, o.phone, COALESCE(o.test_reference, o.redsys_order) AS order_reference,
                    ta.has_allergies, ta.severe_allergy, ta.allergy_notes, ' . $dietaryFields . ',
                    COALESCE((SELECT GROUP_CONCAT(taa.allergen_label ORDER BY taa.allergen_label SEPARATOR " · ") FROM ticket_attendee_allergens taa WHERE taa.attendee_id = ta.id), "") AS allergens
             FROM tickets t
             JOIN ticket_order_items toi ON toi.id = t.order_item_id
             JOIN ticket_orders o ON o.id = toi.order_id
             LEFT JOIN ticket_attendees ta ON ta.ticket_id = t.id
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
        $dietaryFields = $this->ticketAttendeeDietarySchemaAvailable()
            ? 'ta.dietary_preference, ta.dietary_notes'
            : 'NULL AS dietary_preference, NULL AS dietary_notes';
        $stmt = $this->pdo->prepare(
            'SELECT t.*, COALESCE(ta.attendee_name, o.name) AS attendee_name, COALESCE(o.test_reference, o.redsys_order) AS order_reference, toi.ticket_type_name,
                    ta.has_allergies, ta.severe_allergy, ta.allergy_notes, ' . $dietaryFields . ',
                    COALESCE((SELECT GROUP_CONCAT(taa.allergen_label ORDER BY taa.allergen_label SEPARATOR " · ") FROM ticket_attendee_allergens taa WHERE taa.attendee_id = ta.id), "") AS allergens
             FROM tickets t
             JOIN ticket_order_items toi ON toi.id = t.order_item_id
             JOIN ticket_orders o ON o.id = toi.order_id
             LEFT JOIN ticket_attendees ta ON ta.ticket_id = t.id
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
        return ['id' => (int) $ticket['id'], 'public_code' => $ticket['public_code'], 'status' => $ticket['status'], 'access_status' => $ticket['access_status'] ?? 'not_entered', 'first_entry_at' => $ticket['first_entry_at'] ?? null, 'last_entry_at' => $ticket['last_entry_at'] ?? null, 'last_exit_at' => $ticket['last_exit_at'] ?? null, 'entry_count' => (int) ($ticket['entry_count'] ?? 0), 'exit_count' => (int) ($ticket['exit_count'] ?? 0), 'last_access_by' => $ticket['last_access_by'] ?? null, 'attendee_name' => $ticket['attendee_name'], 'ticket_type_name' => $ticket['ticket_type_name'], 'order_reference' => $ticket['order_reference'] ?? null, 'has_allergies' => !empty($ticket['has_allergies']), 'severe_allergy' => !empty($ticket['severe_allergy']), 'allergens' => $ticket['allergens'] ?? '', 'allergy_notes' => $ticket['allergy_notes'] ?? null, 'dietary_preference' => $ticket['dietary_preference'] ?? 'none', 'dietary_notes' => $ticket['dietary_notes'] ?? null];
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
        $event['is_test'] = !empty($event['is_test']);
        $orderCount = $this->pdo->prepare('SELECT COUNT(DISTINCT order_id) FROM ticket_order_items WHERE event_id = ?');
        $orderCount->execute([(int) $event['id']]);
        $event['order_count'] = (int) $orderCount->fetchColumn();
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
        $type['reference_price_cents'] = isset($type['reference_price_cents']) && $type['reference_price_cents'] !== null ? (int) $type['reference_price_cents'] : null;
        $type['fee_cents'] = (int) ($type['fee_cents'] ?? 0);
        $type['tax_rate'] = (float) ($type['tax_rate'] ?? 0);
        $type['capacity'] = (int) $type['capacity'];
        $type['manual_reserve_capacity'] = max(0, (int) ($type['manual_reserve_capacity'] ?? 0));
        $type['min_quantity'] = (int) $type['min_quantity'];
        $type['max_per_order'] = (int) $type['max_per_order'];
        $type['sort_order'] = (int) $type['sort_order'];
        foreach (['active', 'visible', 'requires_promo', 'waitlist_enabled', 'refundable', 'show_reference_price'] as $field) {
            $type[$field] = (bool) ($type[$field] ?? false);
        }
        $type['has_promo_code'] = !empty($type['promo_code_hash']);
        unset($type['promo_code_hash']);
        $type['sold'] = $committed['sold'];
        $type['reserved'] = $committed['reserved'];
        $type['total_capacity'] = $this->totalCapacityForType($type);
        $type['available'] = max(0, $type['total_capacity'] - $committed['sold'] - $committed['reserved']);
        $type['manual_available'] = $this->manualReserveAvailableForType($type, $committed);
        $type['online_available'] = $this->onlineAvailableForType($type, $committed);
        $type['final_price_cents'] = $type['price_cents'] + (int) round($type['price_cents'] * $type['tax_rate'] / 100) + $type['fee_cents'];
        $type['has_reference_price'] = $this->visibleReferencePrice($type, $type['final_price_cents']) !== null;
        $type['promotional_label'] = $this->promotionalLabel($type);
        $type['effective_status'] = $this->ticketEffectiveStatus($type, $type['online_available']);
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
        $manualCondition = $this->manualReserveInventorySchemaAvailable() ? 'tor.inventory_mode="manual_reserve"' : '0';
        $stmt = $this->pdo->prepare(
            'SELECT
              COALESCE(SUM(CASE WHEN tor.status="paid" THEN toi.quantity ELSE 0 END), 0) AS sold,
              COALESCE(SUM(CASE WHEN tor.status IN ("pending","payment_processing") AND tor.reservation_expires_at > NOW() THEN toi.quantity ELSE 0 END), 0) AS reserved,
              COALESCE(SUM(CASE WHEN tor.status="paid" AND ' . $manualCondition . ' THEN toi.quantity ELSE 0 END), 0) AS manual_sold,
              COALESCE(SUM(CASE WHEN tor.status IN ("pending","payment_processing") AND tor.reservation_expires_at > NOW() AND ' . $manualCondition . ' THEN toi.quantity ELSE 0 END), 0) AS manual_reserved
             FROM ticket_order_items toi JOIN ticket_orders tor ON tor.id=toi.order_id WHERE toi.ticket_type_id=? AND tor.is_test = 0'
        );
        $stmt->execute([$ticketTypeId]);
        $row = $stmt->fetch() ?: [];
        return ['sold' => (int) ($row['sold'] ?? 0), 'reserved' => (int) ($row['reserved'] ?? 0), 'manual_sold' => (int) ($row['manual_sold'] ?? 0), 'manual_reserved' => (int) ($row['manual_reserved'] ?? 0)];
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
        if ((int) ($data['reference_price_cents'] ?? 0) < 0) {
            throw new RuntimeException('El valor de referencia no puede ser negativo.');
        }
        $salePrice = (int) ($data['price_cents'] ?? 0) + (int) round((int) ($data['price_cents'] ?? 0) * (float) ($data['tax_rate'] ?? 0) / 100) + (int) ($data['fee_cents'] ?? 0);
        if ($this->shouldShowReferencePrice($data) && $this->referencePrice($data) !== null && $this->referencePrice($data) <= $salePrice) {
            throw new RuntimeException('El valor de la experiencia debe ser superior al precio final de venta.');
        }
        $min = max(1, (int) ($data['min_quantity'] ?? 1));
        $max = max(1, (int) ($data['max_per_order'] ?? 1));
        if ($max < $min) {
            throw new RuntimeException('El maximo por pedido debe ser igual o mayor que el minimo.');
        }
    }

    /** Reference prices are display-only and never take part in payment maths. */
    private function referencePrice(array $type): ?int
    {
        $value = $type['reference_price_cents'] ?? null;
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }
        return (int) $value;
    }

    private function shouldShowReferencePrice(array $type): bool
    {
        return !empty($type['show_reference_price']);
    }

    private function visibleReferencePrice(array $type, int $salePrice): ?int
    {
        $reference = $this->referencePrice($type);
        return $this->shouldShowReferencePrice($type) && $reference !== null && $reference > $salePrice ? $reference : null;
    }

    private function promotionalLabel(array $type): string
    {
        $label = clean_string((string) ($type['promotional_label'] ?? ''), 190);
        return $label !== '' ? $label : 'Precio especial de lanzamiento';
    }

    private function orderItemsWithReference(array $items): array
    {
        return array_map(function (array $item): array {
            $item['reference_unit_price_cents'] = isset($item['reference_unit_price_cents']) && $item['reference_unit_price_cents'] !== null ? (int) $item['reference_unit_price_cents'] : null;
            $item['reference_total_cents'] = isset($item['reference_total_cents']) && $item['reference_total_cents'] !== null ? (int) $item['reference_total_cents'] : null;
            $item['show_reference_price'] = !empty($item['show_reference_price']);
            $item['promotional_label'] = $item['promotional_label'] ?: null;
            return $item;
        }, $items);
    }

    private function orderReferenceTotal(array $items): ?int
    {
        $total = 0;
        $hasReference = false;
        foreach ($items as $item) {
            if (!empty($item['show_reference_price']) && isset($item['reference_total_cents']) && $item['reference_total_cents'] !== null) {
                $total += (int) $item['reference_total_cents'];
                $hasReference = true;
            }
        }
        return $hasReference ? $total : null;
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
            $available = $this->onlineAvailableForType($type);
            $effectiveStatus = $this->ticketEffectiveStatus($type, $available);
            $rows[] = [
                'id' => (int) $type['id'],
                'name' => $type['name'],
                'description' => $type['description'],
                'price_cents' => (int) $type['price_cents'],
                'tax_rate' => (float) ($type['tax_rate'] ?? 0),
                'tax_cents' => (int) round((int) $type['price_cents'] * (float) ($type['tax_rate'] ?? 0) / 100),
                'reference_price_cents' => $this->visibleReferencePrice($type, (int) $type['price_cents'] + (int) round((int) $type['price_cents'] * (float) ($type['tax_rate'] ?? 0) / 100) + (int) ($type['fee_cents'] ?? 0)),
                'promotional_label' => $this->promotionalLabel($type),
                'show_reference_price' => !empty($type['show_reference_price']),
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
            'reference_price_from_cents' => isset($event['reference_price_from_cents']) && $event['reference_price_from_cents'] !== null ? (int) $event['reference_price_from_cents'] : null,
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

    private function lockCashTicketType(int $typeId, int $eventId): ?array
    {
        // Debe coincidir con adminCashOrderMeta(): la taquilla puede usar
        // entradas cerradas, pausadas u ocultas en la web, pero nunca borradores
        // ni entradas archivadas.
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_types WHERE id = ? AND event_id = ? AND status NOT IN ("draft", "archived") FOR UPDATE');
        $stmt->execute([$typeId, $eventId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Conserva el checkout operativo durante un despliegue en el que todavía
     * no se haya aplicado la migración fiscal 023. El importe final no cambia:
     * solo se omite el desglose que la tabla antigua no sabe almacenar.
     */
    private function insertOrderItem(
        int $orderId,
        int $eventId,
        int $ticketTypeId,
        string $ticketTypeName,
        int $quantity,
        int $unitPrice,
        int $unitBase,
        int $unitTax,
        float $taxRate,
        int $unitFee,
        ?int $referenceUnitPrice,
        int $lineTotal,
        ?string $promotionalLabel
    ): int {
        if ($this->ticketItemTaxBreakdownAvailable()) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ticket_order_items
                 (order_id, event_id, ticket_type_id, ticket_type_name, quantity, unit_price_cents, unit_base_cents, unit_tax_cents, tax_rate, unit_fee_cents, reference_unit_price_cents, reference_total_cents, promotional_label, show_reference_price, total_cents, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $statement->execute([$orderId, $eventId, $ticketTypeId, $ticketTypeName, $quantity, $unitPrice, $unitBase, $unitTax, $taxRate, $unitFee, $referenceUnitPrice, $referenceUnitPrice ? $quantity * $referenceUnitPrice : null, $promotionalLabel, $referenceUnitPrice ? 1 : 0, $lineTotal]);
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO ticket_order_items
                 (order_id, event_id, ticket_type_id, ticket_type_name, quantity, unit_price_cents, reference_unit_price_cents, reference_total_cents, promotional_label, show_reference_price, total_cents, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $statement->execute([$orderId, $eventId, $ticketTypeId, $ticketTypeName, $quantity, $unitPrice, $referenceUnitPrice, $referenceUnitPrice ? $quantity * $referenceUnitPrice : null, $promotionalLabel, $referenceUnitPrice ? 1 : 0, $lineTotal]);
        }

        return (int) $this->pdo->lastInsertId();
    }

    private function ticketItemTaxBreakdownAvailable(): bool
    {
        if ($this->ticketItemTaxBreakdownAvailable !== null) {
            return $this->ticketItemTaxBreakdownAvailable;
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM ticket_order_items LIKE 'unit_base_cents'")->fetch();
            return $this->ticketItemTaxBreakdownAvailable = (bool) $column;
        } catch (\Throwable) {
            // Si la comprobación no estuviera permitida, la inserción antigua
            // sigue siendo la opción segura y compatible para el cobro.
            return $this->ticketItemTaxBreakdownAvailable = false;
        }
    }

    /**
     * La migración 025 añade preferencias alimentarias no alérgicas. Durante
     * una actualización, no impedir pedidos que no aportan ese dato; para una
     * necesidad especial sí exigimos el esquema nuevo antes de guardar, de
     * forma que nunca se descarte información de seguridad o de servicio.
     */
    private function ticketAttendeeDietarySchemaAvailable(): bool
    {
        if ($this->ticketAttendeeDietarySchemaAvailable !== null) {
            return $this->ticketAttendeeDietarySchemaAvailable;
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM ticket_attendees LIKE 'dietary_preference'")->fetch();
            return $this->ticketAttendeeDietarySchemaAvailable = (bool) $column;
        } catch (\Throwable) {
            return $this->ticketAttendeeDietarySchemaAvailable = false;
        }
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

    /** Returns trusted prices for a public discount preview without reserving capacity. */
    private function pricedItemsForDiscount(int $eventId, array $requestedItems, bool $testMode = false): array
    {
        $items = [];
        $statement = $this->pdo->prepare(
            'SELECT id, price_cents, tax_rate, fee_cents, min_quantity, max_per_order
             FROM ticket_types
             WHERE id = ? AND event_id = ? AND ' . ($testMode ? 'status <> "archived"' : 'active = 1 AND visible = 1 AND status IN ("on_sale", "upcoming")') . '
             LIMIT 1'
        );
        foreach ($requestedItems as $requested) {
            $typeId = (int) ($requested['ticket_type_id'] ?? 0);
            $quantity = (int) ($requested['quantity'] ?? 0);
            if ($typeId <= 0 || $quantity <= 0) continue;
            $statement->execute([$typeId, $eventId]);
            $type = $statement->fetch();
            if (!$type) continue;
            if ($quantity < (int) $type['min_quantity'] || $quantity > (int) $type['max_per_order']) {
                throw new InvalidArgumentException('Cantidad no permitida para esta entrada.');
            }
            $taxCents = (int) round((int) $type['price_cents'] * (float) ($type['tax_rate'] ?? 0) / 100);
            $items[] = [
                'ticket_type_id' => $typeId,
                'quantity' => $quantity,
                'unit_price_cents' => (int) $type['price_cents'] + $taxCents + (int) ($type['fee_cents'] ?? 0),
            ];
        }
        return $items;
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

    /** Plazas físicas totales: cupo base más cupo adicional para emisión manual. */
    private function totalCapacityForType(array $type): int
    {
        return max(0, (int) ($type['capacity'] ?? 0)) + max(0, (int) ($type['manual_reserve_capacity'] ?? 0));
    }

    /** Plazas que se pueden vender desde el cupo base, sin tocar el manual adicional. */
    private function onlineAvailableForType(array $type, ?array $metrics = null): int
    {
        $metrics ??= $this->ticketMetrics((int) $type['id']);
        $capacity = max(0, (int) ($type['capacity'] ?? 0));
        $manualCommitted = (int) ($metrics['manual_sold'] ?? 0) + (int) ($metrics['manual_reserved'] ?? 0);
        $allCommitted = (int) ($metrics['sold'] ?? 0) + (int) ($metrics['reserved'] ?? 0);
        $standardCommitted = max(0, $allCommitted - $manualCommitted);
        return max(0, $capacity - $standardCommitted);
    }

    private function standardAvailableForType(array $type, ?array $metrics = null): int
    {
        return $this->onlineAvailableForType($type, $metrics);
    }

    /** Plazas del cupo reservado que la operativa manual todavía puede emitir. */
    private function manualReserveAvailableForType(array $type, ?array $metrics = null): int
    {
        $metrics ??= $this->ticketMetrics((int) $type['id']);
        $manualReserve = max(0, (int) ($type['manual_reserve_capacity'] ?? 0));
        $manualCommitted = (int) ($metrics['manual_sold'] ?? 0) + (int) ($metrics['manual_reserved'] ?? 0);
        return max(0, $manualReserve - $manualCommitted);
    }

    /** Convierte el descuento manual en euros a céntimos sin redondeos flotantes. */
    private function cashDiscountCents(mixed $value): int
    {
        $amount = str_replace([' ', '€'], '', trim((string) $value));
        if ($amount === '') return 0;
        if (!preg_match('/^\d+(?:[.,]\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Indica un descuento válido en euros, con un máximo de dos decimales.');
        }
        $parts = preg_split('/[.,]/', $amount);
        $euros = (int) $parts[0];
        $cents = isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0;
        return $euros * 100 + $cents;
    }

    private function cashReservationExpiry(string $value): string
    {
        if (trim($value) === '') {
            return (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('La fecha límite de la reserva no es válida.');
        }
        if ($date->getTimestamp() <= time()) {
            throw new InvalidArgumentException('La fecha límite de la reserva debe ser futura.');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function cashOrderWhatsAppUrl(string $phone, string $name, string $eventTitle, string $publicToken): string
    {
        $recipient = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($recipient) === 9 && (str_starts_with($recipient, '6') || str_starts_with($recipient, '7'))) $recipient = '34' . $recipient;
        $ticketUrl = app_base_url() . '/entradas/pedido/?token=' . rawurlencode($publicToken);
        return 'https://wa.me/' . rawurlencode($recipient) . '?text=' . rawurlencode("Hola {$name},\n\nAquí tienes tus entradas para {$eventTitle}.\n{$ticketUrl}");
    }

    private function cashOrderSchemaAvailable(): bool
    {
        if ($this->cashOrderSchemaAvailable !== null) {
            return $this->cashOrderSchemaAvailable;
        }
        try {
            $this->pdo->query('SELECT sales_channel, cash_payment_status FROM ticket_orders LIMIT 0');
            return $this->cashOrderSchemaAvailable = true;
        } catch (\Throwable) {
            return $this->cashOrderSchemaAvailable = false;
        }
    }

    private function requireCashOrderSchema(): void
    {
        if (!$this->cashOrderSchemaAvailable()) {
            throw new RuntimeException('La operativa de efectivo aún no está preparada en la base de datos. Aplica la migración 024 y vuelve a intentarlo.', 422);
        }
    }

    private function manualReserveInventorySchemaAvailable(): bool
    {
        if ($this->manualReserveInventorySchemaAvailable !== null) {
            return $this->manualReserveInventorySchemaAvailable;
        }
        try {
            $this->pdo->query('SELECT inventory_mode FROM ticket_orders LIMIT 0');
            return $this->manualReserveInventorySchemaAvailable = true;
        } catch (\Throwable) {
            return $this->manualReserveInventorySchemaAvailable = false;
        }
    }

    private function requireManualReserveInventorySchema(): void
    {
        if (!$this->manualReserveInventorySchemaAvailable()) {
            throw new RuntimeException('La emisión desde cupo manual aún no está preparada en la base de datos. Aplica la migración 030 y vuelve a intentarlo.', 422);
        }
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

    private function redsysForm(string $redsysOrder, int $amountCents, array $event, string $publicToken, string $paymentMethod = 'card'): array
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
        if ($paymentMethod === 'bizum') {
            $params['DS_MERCHANT_PAYMETHODS'] = 'z';
        }
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

    /**
     * Validates health-related attendee data on the server. It is never returned
     * in public order or ticket payloads.
     */
    /** @return array{input:string,e164:string,country:string,consent:bool} */
    private function normaliseWhatsAppPhone(array $data): array
    {
        $input = clean_string((string) ($data['phone'] ?? ''), 60);
        // No se infiere +34 en el servidor: el checkout siempre envía la
        // selección visible y, fuera de él, se exige un número E.164 completo.
        $country = strtoupper(clean_string((string) ($data['whatsapp_country_code'] ?? ''), 2));
        if ($country === '' || $country === 'OT') {
            $country = 'ZZ';
        }
        $raw = preg_replace('/[\s().-]+/', '', $input) ?: '';
        if (str_starts_with($raw, '00')) {
            $raw = '+' . substr($raw, 2);
        }
        $prefixes = ['ES' => '34', 'PT' => '351', 'FR' => '33', 'GB' => '44'];
        if (!str_starts_with($raw, '+')) {
            if (!isset($prefixes[$country])) {
                throw new InvalidArgumentException('Para este país, introduce el teléfono con prefijo internacional, por ejemplo +12025550123.');
            }
            $local = ltrim($raw, '0');
            if ($country === 'ES' && !preg_match('/^[6-9][0-9]{8}$/', $local)) {
                throw new InvalidArgumentException('Introduce un teléfono español válido.');
            }
            $raw = '+' . $prefixes[$country] . $local;
        }
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $raw)) {
            throw new InvalidArgumentException('Introduce un teléfono internacional válido.');
        }
        return ['input' => $input, 'e164' => $raw, 'country' => $country, 'consent' => !empty($data['whatsapp_consent'])];
    }

    /** @return array{requested:int,name:?string,tax_id:?string,address:?string,postal_code:?string,city:?string,province:?string,country:?string,email:?string} */
    private function normaliseBilling(array $data): array
    {
        $requested = !empty($data['billing_requested']);
        $empty = ['requested' => 0, 'name' => null, 'tax_id' => null, 'address' => null, 'postal_code' => null, 'city' => null, 'province' => null, 'country' => null, 'email' => null];
        if (!$requested) return $empty;
        $fields = [
            'name' => ['billing_name', 255], 'tax_id' => ['billing_tax_id', 64],
            'address' => ['billing_address', 255], 'postal_code' => ['billing_postal_code', 24],
            'city' => ['billing_city', 120], 'province' => ['billing_province', 120],
        ];
        $result = ['requested' => 1];
        foreach ($fields as $key => [$input, $limit]) {
            $value = clean_string((string) ($data[$input] ?? ''), $limit);
            if ($value === '') throw new InvalidArgumentException('Completa los datos fiscales para solicitar factura.');
            $result[$key] = $value;
        }
        $email = mb_strtolower(clean_string((string) ($data['billing_email'] ?? $data['email'] ?? ''), 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Introduce un correo electrónico fiscal válido.');
        $country = strtoupper(clean_string((string) ($data['billing_country'] ?? 'ES'), 2));
        if (!preg_match('/^[A-Z]{2}$/', $country)) throw new InvalidArgumentException('Indica el país fiscal con código de dos letras.');
        $result['email'] = $email;
        $result['country'] = $country;
        return $result;
    }

    private function normaliseAttendees(mixed $value, int $quantity, string $buyerName): array
    {
        if (!is_array($value) || count($value) !== $quantity) {
            throw new InvalidArgumentException('Completa la información de alergias de cada asistente.');
        }

        $attendees = [];
        foreach ($value as $index => $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('La información de los asistentes no es válida.');
            }
            $name = clean_string((string) ($raw['name'] ?? ''), 190);
            if ($index === 0) {
                $name = $buyerName;
            }
            if ($name === '') {
                throw new InvalidArgumentException('Indica el nombre de cada asistente.');
            }
            $hasAllergies = $this->nullableBoolean($raw['has_allergies'] ?? null);
            // La información alimentaria es opcional: si no se declara nada,
            // se registra explícitamente que no hay alerta para el acceso.
            if ($hasAllergies === null) $hasAllergies = false;
            $allergens = [];
            if ($hasAllergies) {
                if (!is_array($raw['allergens'] ?? null)) {
                    throw new InvalidArgumentException('Selecciona los alérgenos relevantes para cada asistente.');
                }
                foreach ($raw['allergens'] as $allergen) {
                    $id = (string) $allergen;
                    if (!isset(self::FOOD_ALLERGENS[$id])) {
                        throw new InvalidArgumentException('Se ha indicado un alérgeno no válido.');
                    }
                    $allergens[$id] = self::FOOD_ALLERGENS[$id];
                }
                if (!$allergens) {
                    throw new InvalidArgumentException('Selecciona al menos un alérgeno para cada asistente que lo necesite.');
                }
                $severeAllergy = $this->nullableBoolean($raw['severe_allergy'] ?? null);
                if ($severeAllergy === null) {
                    throw new InvalidArgumentException('Indica si la alergia es grave.');
                }
            } else {
                $severeAllergy = false;
            }
            $dietaryPreference = clean_string((string) ($raw['dietary_preference'] ?? 'none'), 32);
            $allowedDietaryPreferences = ['none', 'vegetarian', 'vegan', 'pescatarian', 'other'];
            if (!in_array($dietaryPreference, $allowedDietaryPreferences, true)) {
                throw new InvalidArgumentException('Se ha indicado una dieta o necesidad alimentaria no válida.');
            }
            $dietaryNotes = $dietaryPreference === 'other'
                ? clean_string((string) ($raw['dietary_notes'] ?? ''), 500)
                : null;
            if ($dietaryPreference === 'other' && $dietaryNotes === '') {
                throw new InvalidArgumentException('Describe la necesidad alimentaria indicada.');
            }
            $attendees[] = [
                'name' => $name,
                'has_allergies' => $hasAllergies,
                'allergens' => $allergens,
                'severe_allergy' => $severeAllergy,
                'allergy_notes' => $hasAllergies ? clean_string((string) ($raw['allergy_notes'] ?? ''), 500) : null,
                'dietary_preference' => $dietaryPreference,
                'dietary_notes' => $dietaryNotes,
            ];
        }
        return $attendees;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        return null;
    }

    private function persistAttendees(int $orderId, array $orderItems, array $attendees): void
    {
        $dietarySchemaAvailable = $this->ticketAttendeeDietarySchemaAvailable();
        if (!$dietarySchemaAvailable) {
            foreach ($attendees as $attendee) {
                if (($attendee['dietary_preference'] ?? 'none') !== 'none') {
                    throw new InvalidArgumentException('No se puede registrar una dieta especial hasta terminar la actualización técnica. Inténtalo de nuevo en unos minutos o contacta con Perigallo.');
                }
            }
        }
        $attendeeInsert = $this->pdo->prepare(
            $dietarySchemaAvailable
                ? 'INSERT INTO ticket_attendees
                   (order_id, order_item_id, ticket_sequence, attendee_name, has_allergies, severe_allergy, allergy_notes, dietary_preference, dietary_notes, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                : 'INSERT INTO ticket_attendees
                   (order_id, order_item_id, ticket_sequence, attendee_name, has_allergies, severe_allergy, allergy_notes, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $allergenInsert = $this->pdo->prepare(
            'INSERT INTO ticket_attendee_allergens (attendee_id, allergen_id, allergen_label, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        $position = 0;
        foreach ($orderItems as $item) {
            for ($sequence = 1; $sequence <= (int) $item['quantity']; $sequence++) {
                $attendee = $attendees[$position++] ?? null;
                if (!$attendee) {
                    throw new RuntimeException('No se ha podido asociar la información de los asistentes a las entradas.');
                }
                $values = [
                    $orderId,
                    (int) $item['id'],
                    $sequence,
                    $attendee['name'],
                    $attendee['has_allergies'] ? 1 : 0,
                    $attendee['severe_allergy'] ? 1 : 0,
                    $attendee['allergy_notes'],
                ];
                if ($dietarySchemaAvailable) {
                    $values[] = $attendee['dietary_preference'];
                    $values[] = $attendee['dietary_notes'];
                }
                $attendeeInsert->execute($values);
                $attendeeId = (int) $this->pdo->lastInsertId();
                foreach ($attendee['allergens'] as $id => $label) {
                    $allergenInsert->execute([$attendeeId, $id, $label]);
                }
            }
        }
    }

    private function persistCashAttendees(int $orderId, array $orderItems, string $buyerName): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO ticket_attendees (order_id, order_item_id, ticket_sequence, attendee_name, has_allergies, severe_allergy, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, 0, NOW(), NOW())'
        );
        foreach ($orderItems as $item) {
            for ($sequence = 1; $sequence <= (int) $item['quantity']; $sequence++) {
                $insert->execute([$orderId, (int) $item['id'], $sequence, $buyerName]);
            }
        }
    }

    private function generateTicketsOnce(int $orderId, string $ticketStatus = 'issued'): void
    {
        if (!in_array($ticketStatus, ['issued', 'blocked'], true)) {
            throw new InvalidArgumentException('El estado inicial de la entrada no es válido.');
        }
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
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())'
        );
        $attendeeUpdate = $this->pdo->prepare(
            'UPDATE ticket_attendees SET ticket_id = ?, updated_at = NOW() WHERE order_item_id = ? AND ticket_sequence = ?'
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
                    $ticketStatus,
                ]);
                $attendeeUpdate->execute([(int) $this->pdo->lastInsertId(), (int) $item['id'], $i + 1]);
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
        try {
            (new Contacts($this->pdo))->syncPaidOrder($orderId);
        } catch (\Throwable $error) {
            error_log('Perigallo contact sync failed for order ' . $orderId . ': ' . $error->getMessage());
        }
        // Esta operación solo crea trabajos en base de datos. El cron los procesa
        // después de responder a Redsys, por lo que un envío lento no afecta al pago.
        (new TicketDeliveryQueue($this->pdo, $this->mailer))->enqueuePaidOrder($orderId);
    }

    private function sendTestConfirmation(int $orderId): void
    {
        $this->sendConfirmation($orderId);
    }
}
