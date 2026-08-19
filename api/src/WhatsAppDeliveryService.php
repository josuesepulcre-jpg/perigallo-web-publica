<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;
use RuntimeException;

/** Official Meta WhatsApp Cloud API adapter for transactional ticket documents. */
final class WhatsAppDeliveryService
{
    public const TEMPLATE_NAME = 'entradas_perigallo_confirmadas_v1';
    public const TEMPLATE_LANGUAGE = 'es_ES';

    /** @return array{configured:bool,status:string,template:string,language:string,reason:?string} */
    public function templateStatus(): array
    {
        $config = $this->config();
        if (!$config['phone_number_id'] || !$config['access_token'] || !$config['waba_id']) {
            return ['configured' => false, 'status' => 'not_configured', 'template' => $config['template'], 'language' => $config['language'], 'reason' => 'Faltan credenciales de WhatsApp Business Platform.'];
        }
        if (!function_exists('curl_init')) {
            return ['configured' => false, 'status' => 'not_configured', 'template' => $config['template'], 'language' => $config['language'], 'reason' => 'El servidor no dispone de cURL.'];
        }
        try {
            $result = $this->requestJson('GET', '/' . rawurlencode($config['waba_id']) . '/message_templates?name=' . rawurlencode($config['template']) . '&limit=20', null, $config);
            foreach ((array) ($result['data'] ?? []) as $template) {
                if (($template['name'] ?? '') === $config['template']) {
                    return ['configured' => true, 'status' => $this->normaliseTemplateStatus((string) ($template['status'] ?? 'UNKNOWN')), 'template' => $config['template'], 'language' => $config['language'], 'reason' => null];
                }
            }
            return ['configured' => true, 'status' => 'not_created', 'template' => $config['template'], 'language' => $config['language'], 'reason' => 'La plantilla no aparece todavía en la cuenta de WhatsApp Business.'];
        } catch (RuntimeException $error) {
            return ['configured' => true, 'status' => 'unknown', 'template' => $config['template'], 'language' => $config['language'], 'reason' => 'No se ha podido consultar el estado de la plantilla en Meta.'];
        }
    }

    /** @return array{status:string,message_id:?string,error:?string} */
    public function sendTicketDocument(PDO $pdo, array $order, array $event, int $quantity, array $document, string $idempotencyKey): array
    {
        $config = $this->config();
        $recipient = (string) ($order['whatsapp_phone_e164'] ?? '');
        if (empty($order['whatsapp_consent']) || $recipient === '') {
            $this->upsertLog($pdo, (int) $order['id'], $idempotencyKey, 'not_authorized', $this->maskPhone($recipient), $config, null, null, 'No existe consentimiento de WhatsApp para esta compra.');
            return ['status' => 'not_authorized', 'message_id' => null, 'error' => null];
        }
        $template = $this->templateStatus();
        if (($template['status'] ?? '') !== 'approved') {
            $error = $template['reason'] ?? 'La plantilla de WhatsApp no está aprobada.';
            $this->upsertLog($pdo, (int) $order['id'], $idempotencyKey, 'blocked', $this->maskPhone($recipient), $config, null, null, $error);
            return ['status' => 'blocked', 'message_id' => null, 'error' => $error];
        }
        if (!function_exists('curl_init')) {
            $error = 'El servidor no dispone de cURL para WhatsApp.';
            $this->upsertLog($pdo, (int) $order['id'], $idempotencyKey, 'failed', $this->maskPhone($recipient), $config, null, null, $error);
            return ['status' => 'retry', 'message_id' => null, 'error' => $error];
        }
        $existing = $this->existingLog($pdo, $idempotencyKey);
        if ($existing && in_array((string) $existing['status'], ['sent', 'delivered', 'read'], true)) {
            return ['status' => (string) $existing['status'], 'message_id' => $existing['provider_message_id'] ?: null, 'error' => null];
        }
        try {
            $mediaId = $this->uploadDocument((string) $document['content'], (string) $document['filename'], $config);
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($recipient, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $config['template'],
                    'language' => ['code' => $config['language']],
                    'components' => [
                        ['type' => 'header', 'parameters' => [[
                            'type' => 'document',
                            'document' => ['id' => $mediaId, 'filename' => (string) $document['filename']],
                        ]]],
                        ['type' => 'body', 'parameters' => [
                            ['type' => 'text', 'text' => $this->safeText((string) ($order['name'] ?? ''))],
                            ['type' => 'text', 'text' => $this->safeText((string) ($event['title'] ?? 'Perigallo'))],
                            ['type' => 'text', 'text' => $this->safeText((string) (($order['test_reference'] ?? '') ?: ($order['redsys_order'] ?? '')))],
                            ['type' => 'text', 'text' => (string) $quantity],
                            ['type' => 'text', 'text' => $this->eventDate((string) ($event['starts_at'] ?? ''))],
                            ['type' => 'text', 'text' => $this->safeText((string) ($order['email'] ?? ''))],
                        ]],
                    ],
                ],
            ];
            $response = $this->requestJson('POST', '/' . rawurlencode($config['phone_number_id']) . '/messages', $payload, $config);
            $messageId = (string) ($response['messages'][0]['id'] ?? '');
            if ($messageId === '') {
                throw new RuntimeException('Meta no devolvió identificador de mensaje.');
            }
            $this->upsertLog($pdo, (int) $order['id'], $idempotencyKey, 'sent', $this->maskPhone($recipient), $config, $messageId, [
                'provider' => 'meta_cloud',
                'document_version' => TicketDocumentService::DOCUMENT_VERSION,
                'document_sha256' => (string) ($document['sha256'] ?? ''),
                'ticket_quantity' => $quantity,
            ]);
            return ['status' => 'sent', 'message_id' => $messageId, 'error' => null];
        } catch (RuntimeException $error) {
            $safeError = 'Meta no aceptó el envío del documento.';
            $this->upsertLog($pdo, (int) $order['id'], $idempotencyKey, 'failed', $this->maskPhone($recipient), $config, null, null, $safeError);
            return ['status' => 'retry', 'message_id' => null, 'error' => $safeError];
        }
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = $this->config()['app_secret'];
        return $secret !== '' && str_starts_with($signature, 'sha256=') && hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function webhookVerifyToken(): string
    {
        return $this->config()['webhook_verify_token'];
    }

    /** @return int Number of status updates stored. */
    public function recordWebhookStatuses(PDO $pdo, array $payload): int
    {
        $count = 0;
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $messageId = (string) ($status['id'] ?? '');
                    $mapped = $this->normaliseMessageStatus((string) ($status['status'] ?? ''));
                    if ($messageId === '' || $mapped === null) continue;
                    $lookup = $pdo->prepare('SELECT id FROM ticket_delivery_logs WHERE provider_message_id = ? LIMIT 1');
                    $lookup->execute([$messageId]);
                    $logId = (int) $lookup->fetchColumn();
                    if (!$logId) continue;
                    $timestamp = isset($status['timestamp']) && ctype_digit((string) $status['timestamp']) ? date('Y-m-d H:i:s', (int) $status['timestamp']) : date('Y-m-d H:i:s');
                    $error = (array) (($status['errors'] ?? [])[0] ?? []);
                    $errorCode = isset($error['code']) ? substr((string) $error['code'], 0, 80) : null;
                    $errorMessage = $mapped === 'failed' ? 'Meta informó un fallo de entrega.' : null;
                    $update = $pdo->prepare('UPDATE ticket_delivery_logs SET status = ?, error_code = ?, error_message = ?, sent_at = IF(? = "sent", COALESCE(sent_at, ?), sent_at), delivered_at = IF(? = "delivered", COALESCE(delivered_at, ?), delivered_at), read_at = IF(? = "read", COALESCE(read_at, ?), read_at), last_status_at = ?, updated_at = NOW() WHERE id = ?');
                    $update->execute([$mapped, $errorCode, $errorMessage, $mapped, $timestamp, $mapped, $timestamp, $mapped, $timestamp, $timestamp, $logId]);
                    $event = $pdo->prepare('INSERT INTO ticket_delivery_status_events (delivery_log_id, status, provider_timestamp, error_code, error_message, payload, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
                    $event->execute([$logId, $mapped, $timestamp, $errorCode, $errorMessage, json_encode(['provider_status' => $status['status'] ?? ''], JSON_UNESCAPED_UNICODE), ]);
                    $count++;
                }
            }
        }
        return $count;
    }

    private function uploadDocument(string $content, string $filename, array $config): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'perigallo-wa-');
        if ($temporary === false) throw new RuntimeException('No se pudo preparar el documento.');
        try {
            if (file_put_contents($temporary, $content, LOCK_EX) === false) throw new RuntimeException('No se pudo preparar el documento.');
            $response = $this->requestJson('POST', '/' . rawurlencode($config['phone_number_id']) . '/media', [
                'messaging_product' => 'whatsapp', 'type' => 'application/pdf',
                'file' => new \CURLFile($temporary, 'application/pdf', $filename),
            ], $config, true);
            $mediaId = (string) ($response['id'] ?? '');
            if ($mediaId === '') throw new RuntimeException('Meta no devolvió identificador de documento.');
            return $mediaId;
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private function requestJson(string $method, string $path, ?array $payload, array $config, bool $multipart = false): array
    {
        $curl = curl_init('https://graph.facebook.com/' . rawurlencode($config['graph_version']) . $path);
        if ($curl === false) throw new RuntimeException('No se pudo iniciar la conexión con Meta.');
        $headers = ['Authorization: Bearer ' . $config['access_token']];
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $multipart ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!$multipart) $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($code < 200 || $code >= 300 || !is_array($decoded)) throw new RuntimeException('Meta rechazó la solicitud.');
        return $decoded;
    }

    private function upsertLog(PDO $pdo, int $orderId, string $idempotencyKey, string $status, string $recipient, array $config, ?string $messageId, array|string|null $payload = null, ?string $error = null): void
    {
        $encoded = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $payload;
        $statement = $pdo->prepare('INSERT INTO ticket_delivery_logs (order_id, idempotency_key, channel, status, recipient, provider_message_id, template_name, template_language, document_version, payload, error_message, sent_at, last_status_at, created_at, updated_at) VALUES (?, ?, "whatsapp", ?, ?, ?, ?, ?, ?, ?, ?, IF(? = "sent", NOW(), NULL), NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), recipient = VALUES(recipient), provider_message_id = COALESCE(VALUES(provider_message_id), provider_message_id), template_name = VALUES(template_name), template_language = VALUES(template_language), document_version = VALUES(document_version), payload = COALESCE(VALUES(payload), payload), error_message = VALUES(error_message), sent_at = IF(VALUES(status) = "sent", COALESCE(sent_at, NOW()), sent_at), last_status_at = NOW(), updated_at = NOW()');
        $statement->execute([$orderId, $idempotencyKey, $status, $recipient, $messageId, $config['template'], $config['language'], TicketDocumentService::DOCUMENT_VERSION, $encoded, $error, $status]);
    }

    private function existingLog(PDO $pdo, string $idempotencyKey): ?array
    {
        $statement = $pdo->prepare('SELECT status, provider_message_id FROM ticket_delivery_logs WHERE idempotency_key = ? LIMIT 1');
        $statement->execute([$idempotencyKey]);
        return $statement->fetch() ?: null;
    }

    private function config(): array
    {
        return [
            'waba_id' => $this->envAny(['META_WABA_ID', 'WHATSAPP_WABA_ID']),
            'phone_number_id' => $this->envAny(['META_PHONE_NUMBER_ID', 'WHATSAPP_PHONE_NUMBER_ID']),
            'access_token' => $this->envAny(['META_ACCESS_TOKEN', 'WHATSAPP_ACCESS_TOKEN']),
            'app_secret' => $this->envAny(['META_APP_SECRET', 'WHATSAPP_APP_SECRET']),
            'webhook_verify_token' => $this->envAny(['META_WEBHOOK_VERIFY_TOKEN', 'WHATSAPP_WEBHOOK_VERIFY_TOKEN']),
            'graph_version' => $this->envAny(['META_GRAPH_VERSION', 'META_GRAPH_API_VERSION', 'WHATSAPP_GRAPH_API_VERSION'], 'v23.0'),
            'template' => $this->envAny(['META_TEMPLATE_NAME', 'META_WHATSAPP_TEMPLATE', 'WHATSAPP_TEMPLATE'], self::TEMPLATE_NAME),
            'language' => $this->envAny(['META_TEMPLATE_LANGUAGE', 'META_WHATSAPP_TEMPLATE_LANGUAGE', 'WHATSAPP_TEMPLATE_LANGUAGE'], self::TEMPLATE_LANGUAGE),
        ];
    }

    private function envAny(array $keys, string $default = ''): string
    {
        foreach ($keys as $key) { $value = env_value($key); if ($value !== null && $value !== '') return $value; }
        return $default;
    }

    private function normaliseTemplateStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'APPROVED' => 'approved', 'PENDING', 'IN_REVIEW' => 'in_review', 'REJECTED' => 'rejected', 'PAUSED' => 'paused', 'DISABLED' => 'disabled', default => 'unknown',
        };
    }

    private function normaliseMessageStatus(string $status): ?string
    {
        return match (strtolower($status)) { 'sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', default => null };
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        return $digits === '' ? 'Sin teléfono' : '+' . substr($digits, 0, min(3, strlen($digits))) . str_repeat('•', max(0, strlen($digits) - 5)) . substr($digits, -2);
    }

    private function eventDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? 'Fecha por confirmar' : date('d/m/Y H:i', $timestamp);
    }

    private function safeText(string $value): string
    {
        return trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?: '', 0, 190));
    }
}
