<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;

/**
 * Delivery is deliberately provider-agnostic. WhatsApp stays simulated until
 * a transactional provider is configured; the log makes that explicit.
 */
final class TicketDeliveryService
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function sendTestOrder(PDO $pdo, array $order, array $event, int $quantity): array
    {
        $orderId = (int) $order['id'];
        $link = app_base_url() . '/entradas/pedido/?token=' . rawurlencode((string) $order['public_token']);
        $subject = '[PRUEBA] Tus entradas para ' . (string) $event['title'];
        $body = "MODO DE PRUEBAS · No corresponde a una compra real.\n\nHola {$order['name']},\n\nTu experiencia de prueba para {$event['title']} se ha confirmado.\n\nEntradas: {$quantity}\nConsulta tus entradas: {$link}\n\nEquipo Perigallo\n";
        $this->mailer->queueOrderEmail($pdo, $orderId, (string) $order['email'], $subject, $body);

        $emailStatus = (string) ($pdo->query('SELECT status FROM email_deliveries WHERE id = LAST_INSERT_ID()')->fetchColumn() ?: 'failed');
        $phone = $this->normalisePhone((string) $order['phone']);
        $message = "MENSAJE DE PRUEBA · No corresponde a una compra real.\n\nHola {$order['name']}.\n\nTu compra para {$event['title']} ha sido confirmada.\nEntradas: {$quantity}\n\nPuedes consultar tus entradas aquí:\n{$link}\n\nEquipo Perigallo";
        $log = $pdo->prepare(
            'INSERT INTO ticket_delivery_logs (order_id, channel, status, recipient, payload, created_at, updated_at)
             VALUES (?, "whatsapp", "simulated", ?, ?, NOW(), NOW())'
        );
        $log->execute([$orderId, $phone, $message]);

        return ['email' => $emailStatus, 'whatsapp' => 'simulated'];
    }

    private function normalisePhone(string $phone): string
    {
        $clean = preg_replace('/[^0-9+]/', '', $phone) ?: '';
        if ($clean !== '' && $clean[0] !== '+') {
            $clean = '+34' . ltrim($clean, '0');
        }
        return $clean ?: 'sin teléfono';
    }
}
