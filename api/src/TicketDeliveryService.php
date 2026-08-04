<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;

/** Orchestrates only verifiable delivery attempts. */
final class TicketDeliveryService
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function sendOrder(PDO $pdo, array $order, array $event, int $quantity): array
    {
        $orderId = (int) $order['id'];
        $link = app_base_url() . '/entradas/pedido/?token=' . rawurlencode((string) $order['public_token']);
        $isTest = !empty($order['is_test']);
        $subject = ($isTest ? '[PRUEBA] ' : '') . 'Tus entradas para ' . (string) $event['title'];
        $intro = $isTest ? "MODO DE PRUEBAS · No corresponde a una compra real.\n\n" : '';
        $body = $intro . "Hola {$order['name']},\n\n" . ($isTest ? 'Tu experiencia de prueba' : 'Tu pago') . " para {$event['title']} se ha confirmado.\n\nEntradas: {$quantity}\nConsulta y descarga tus entradas: {$link}\n\nEquipo Perigallo\n";
        $this->mailer->queueOrderEmail($pdo, $orderId, (string) $order['email'], $subject, $body);

        $emailStatus = (string) ($pdo->query('SELECT status FROM email_deliveries WHERE id = LAST_INSERT_ID()')->fetchColumn() ?: 'failed');
        $whatsAppStatus = (new WhatsAppDeliveryService())->sendOrder($pdo, $order, (string) $event['title'], $quantity);

        return ['email' => $emailStatus, 'whatsapp' => $whatsAppStatus];
    }
}
