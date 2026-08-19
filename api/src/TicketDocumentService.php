<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use PDO;
use RuntimeException;

/** Generates and stores one protected, immutable PDF for each paid order. */
final class TicketDocumentService
{
    public const DOCUMENT_TYPE = 'tickets';
    public const DOCUMENT_VERSION = 'v1';

    /** @return array{id:int,filename:string,content:string,content_type:string,sha256:string} */
    public function ensureOrderDocument(PDO $pdo, int $orderId): array
    {
        $existing = $this->document($pdo, $orderId);
        if ($existing !== null) {
            return $existing;
        }

        $orderStatement = $pdo->prepare('SELECT * FROM ticket_orders WHERE id = ? LIMIT 1');
        $orderStatement->execute([$orderId]);
        $order = $orderStatement->fetch();
        if (!$order || !in_array((string) ($order['status'] ?? ''), ['paid'], true) && (string) ($order['payment_status'] ?? '') !== 'paid') {
            throw new RuntimeException('El PDF solo puede generarse para un pedido pagado.');
        }

        $tickets = $pdo->prepare(
            'SELECT t.id, t.public_code, t.qr_token_hash, t.qr_token_ciphertext, t.status,
                    e.title AS event_title, e.subtitle AS event_subtitle, e.starts_at, e.location, e.locality,
                    toi.ticket_type_name
             FROM tickets t
             JOIN ticket_order_items toi ON toi.id = t.order_item_id
             JOIN events e ON e.id = t.event_id
             WHERE toi.order_id = ? AND t.status IN ("issued", "used")
             ORDER BY t.id ASC'
        );
        $tickets->execute([$orderId]);
        $rows = $tickets->fetchAll();
        if (!$rows) {
            throw new RuntimeException('El pedido pagado todavía no tiene entradas para preparar el PDF.');
        }

        $payloadTickets = [];
        foreach ($rows as $ticket) {
            $payloadTickets[] = [
                'event_title' => $ticket['event_title'],
                'event_subtitle' => $ticket['event_subtitle'],
                'starts_at' => $this->formattedDate((string) ($ticket['starts_at'] ?? '')),
                'location' => $ticket['location'],
                'locality' => $ticket['locality'],
                'ticket_type_name' => $ticket['ticket_type_name'],
                'public_code' => $ticket['public_code'],
                'qr_value' => app_base_url() . '/check-in/?ticket=' . rawurlencode($this->ticketQrToken($pdo, $ticket)),
            ];
        }
        $firstTicket = $payloadTickets[0];
        $reference = (string) (($order['test_reference'] ?? '') ?: ($order['redsys_order'] ?? $orderId));
        $content = $this->render([
            'reference' => $reference,
            'is_test' => !empty($order['is_test']),
            'tickets' => $payloadTickets,
        ]);
        $filename = 'Entradas-' . $this->safeFilename((string) $firstTicket['event_title']) . '-' . $this->safeFilename($reference) . '.pdf';
        $hash = hash('sha256', $content);

        $save = $pdo->prepare(
            'INSERT INTO ticket_delivery_documents
             (order_id, document_type, document_version, filename, content_type, content, content_sha256, created_at, updated_at)
             VALUES (?, ?, ?, ?, "application/pdf", ?, ?, NOW(), NOW())'
        );
        try {
            $save->execute([$orderId, self::DOCUMENT_TYPE, self::DOCUMENT_VERSION, $filename, $content, $hash]);
        } catch (\Throwable $error) {
            // Dos trabajos pueden llegar a la vez tras un reinicio. El índice único
            // conserva el primer documento y evita regenerar QR distintos.
            $existing = $this->document($pdo, $orderId);
            if ($existing !== null) {
                return $existing;
            }
            throw $error;
        }
        return $this->document($pdo, $orderId) ?? throw new RuntimeException('No se pudo guardar el PDF de las entradas.');
    }

    /** @return array{id:int,filename:string,content:string,content_type:string,sha256:string}|null */
    public function document(PDO $pdo, int $orderId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, filename, content, content_type, content_sha256
             FROM ticket_delivery_documents
             WHERE order_id = ? AND document_type = ? AND document_version = ?
             LIMIT 1'
        );
        $statement->execute([$orderId, self::DOCUMENT_TYPE, self::DOCUMENT_VERSION]);
        $document = $statement->fetch();
        if (!$document) {
            return null;
        }
        return [
            'id' => (int) $document['id'],
            'filename' => (string) $document['filename'],
            'content' => (string) $document['content'],
            'content_type' => (string) $document['content_type'],
            'sha256' => (string) $document['content_sha256'],
        ];
    }

    private function render(array $payload): string
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('El servidor no permite generar el PDF de entradas todavía.');
        }
        $node = env_value('TICKET_PDF_NODE_BINARY', 'node') ?: 'node';
        $renderer = dirname(__DIR__) . '/scripts/render-ticket-pdf.mjs';
        if (!is_file($renderer)) {
            throw new RuntimeException('No se encuentra el renderizador local del PDF de entradas.');
        }
        $process = proc_open([$node, $renderer], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el renderizador del PDF de entradas.');
        }
        try {
            fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fclose($pipes[0]);
            $content = stream_get_contents($pipes[1]);
            $diagnostic = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        } catch (\Throwable $error) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            throw $error;
        }
        if ($exit !== 0 || !is_string($content) || !str_starts_with($content, '%PDF-') || strlen($content) > 20 * 1024 * 1024) {
            // No se incluye la salida del renderer: puede contener información de una entrada.
            throw new RuntimeException('No se pudo generar el PDF de las entradas.');
        }
        return $content;
    }

    private function ticketQrToken(PDO $pdo, array $ticket): string
    {
        $token = $this->decryptQrToken((string) ($ticket['qr_token_ciphertext'] ?? ''));
        if ($token !== '') {
            return $token;
        }
        $token = public_token(32);
        $update = $pdo->prepare('UPDATE tickets SET qr_token_hash = ?, qr_token_ciphertext = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([hash('sha256', $token), $this->encryptQrToken($token), (int) $ticket['id']]);
        return $token;
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
            throw new RuntimeException('El servidor no dispone del cifrado necesario para los códigos QR.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $this->qrEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('No se pudo proteger el código QR.');
        }
        return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
    }

    private function decryptQrToken(string $value): string
    {
        if ($value === '' || !function_exists('openssl_decrypt')) {
            return '';
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

    private function formattedDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? 'Fecha por confirmar' : date('d/m/Y · H:i', $timestamp);
    }

    private function safeFilename(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: '') ?: '';
        return trim(substr($value, 0, 80), '-') ?: 'Perigallo';
    }
}
