<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;

/**
 * Adapter for transactional WhatsApp delivery. It is intentionally inactive
 * unless the business has configured an approved Meta template and credentials.
 */
final class WhatsAppDeliveryService
{
    public function sendOrder(PDO $pdo, array $order, string $eventTitle, int $quantity): string
    {
        $recipient = $this->normalisePhone((string) ($order['phone'] ?? ''));
        $orderId = (int) ($order['id'] ?? 0);
        $provider = env_value('WHATSAPP_PROVIDER', '');
        $enabled = filter_var(env_value('WHATSAPP_AUTO_SEND', 'false'), FILTER_VALIDATE_BOOLEAN);
        $phoneNumberId = env_value('WHATSAPP_PHONE_NUMBER_ID', '');
        $accessToken = env_value('WHATSAPP_ACCESS_TOKEN', '');
        $template = env_value('WHATSAPP_TEMPLATE', '');

        if (!$enabled || $provider !== 'meta_cloud' || !$phoneNumberId || !$accessToken || !$template || $recipient === 'sin teléfono') {
            $this->log($pdo, $orderId, 'not_configured', $recipient, 'WhatsApp no se ha enviado: falta configurar proveedor, opt-in y plantilla aprobada.');
            return 'not_configured';
        }

        if (!function_exists('curl_init')) {
            $this->log($pdo, $orderId, 'failed', $recipient, null, 'El servidor no dispone de cURL para WhatsApp.');
            return 'failed';
        }

        // Las plantillas se aprueban en Meta antes de activarlas. No interpolamos
        // secretos ni enlaces de pedido en registros ni en una plantilla no validada.
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($recipient, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => env_value('WHATSAPP_TEMPLATE_LANGUAGE', 'es') ?: 'es'],
            ],
        ];
        $curl = curl_init('https://graph.facebook.com/v20.0/' . rawurlencode($phoneNumberId) . '/messages');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $messageId = is_array($decoded) ? (string) ($decoded['messages'][0]['id'] ?? '') : '';
        if ($statusCode >= 200 && $statusCode < 300 && $messageId !== '') {
            $this->log($pdo, $orderId, 'sent', $recipient, json_encode(['provider' => 'meta_cloud', 'message_id' => $messageId, 'event' => $eventTitle, 'quantity' => $quantity], JSON_UNESCAPED_UNICODE));
            return 'sent';
        }

        $this->log($pdo, $orderId, 'failed', $recipient, null, 'Meta no aceptó el envío de WhatsApp.' . ($curlError ? ' ' . $curlError : ''));
        return 'failed';
    }

    private function log(PDO $pdo, int $orderId, string $status, string $recipient, ?string $payload = null, ?string $error = null): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO ticket_delivery_logs (order_id, channel, status, recipient, payload, error_message, created_at, updated_at)
             VALUES (?, "whatsapp", ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$orderId, $status, $recipient, $payload, $error]);
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
